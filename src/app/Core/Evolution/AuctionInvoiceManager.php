<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Container;
use App\Domain\Tax\TaxEngine;
use App\Domain\Web\Models\ListingModel;
use App\Domain\Web\Models\TransactionModel;
use App\Domain\Web\Services\Payments\MolliePaymentService;
use App\Domain\Web\Services\Payments\StripePaymentService;

/**
 * Post-payment invoice pipeline for auctions / escrow: line classification, PSP matching, TaxEngine probes,
 * self-billing stub, and reasoning.json-style audit (EvolutionCore).
 *
 * Fiscal correctness remains operator responsibility; this codifies structure and audit trail.
 */
final class AuctionInvoiceManager
{
    private const REASONING_SCHEMA = 'auction_invoice_reasoning_v1';

    /**
     * Run after escrow payment is confirmed (Mollie/Stripe webhook or buyer confirm).
     *
     * @return array{ok: bool, skipped?: string, reasoning_path?: string, artifact_html_path?: string, psp_match?: array<string, mixed>}
     */
    public function processAfterEscrowPayment(
        Container $container,
        int $transactionId,
        string $paymentReference,
        string $pspGuess = 'auto'
    ): array {
        $cfg = $container->get('config');
        $inv = $cfg->get('invoicing', []);
        if (!is_array($inv) || !filter_var($inv['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return ['ok' => true, 'skipped' => 'invoicing_disabled'];
        }

        $txModel = new TransactionModel($container);
        if (!$txModel->isTransactionsAvailable()) {
            return ['ok' => false, 'skipped' => 'no_transactions_table'];
        }

        $transaction = $txModel->findById($transactionId);
        if ($transaction === null) {
            return ['ok' => false, 'skipped' => 'transaction_not_found'];
        }

        $psp = $this->resolvePsp($paymentReference, $pspGuess);
        $pspSnapshot = $this->fetchPspSnapshot($container, $paymentReference, $psp);

        $listing = null;
        $lid = (int)($transaction['listing_id'] ?? 0);
        if ($lid > 0) {
            $listingModel = new ListingModel($container);
            $listing = $listingModel->findById($lid, 'en');
        }

        $lines = $this->buildInvoiceLines($container, $transaction, $listing, $pspSnapshot);
        $taxProbe = $lines['buyer_premium_tax_probe'] ?? null;
        unset($lines['buyer_premium_tax_probe']);
        $match = $this->matchPspToTransactionTotal($transaction, $pspSnapshot);

        $reasoning = [
            'schema' => self::REASONING_SCHEMA,
            'transaction_id' => $transactionId,
            'escrow_reference' => (string)($transaction['escrow_reference'] ?? ''),
            'is_auction_escrow' => str_starts_with((string)($transaction['escrow_reference'] ?? ''), 'AUC-'),
            'payment_reference' => $paymentReference,
            'psp' => $psp,
            'generated_at' => gmdate('c'),
            'lines' => $lines,
            'psp_amount_match' => $match,
            'self_billing' => $this->selfBillingStub($container, $transaction),
            'compliance_notes' => $this->complianceNotes($lines, $pspSnapshot, $cfg),
            'tax_engine' => $taxProbe,
        ];

        $paths = $this->writeArtifacts($container, $transactionId, $reasoning, $lines);

        EvolutionLogger::log('invoice', 'escrow_settled', [
            'transaction_id' => $transactionId,
            'psp' => $psp,
            'psp_match_ok' => $match['ok'] ?? false,
        ]);

        return [
            'ok' => true,
            'reasoning_path' => $paths['reasoning'],
            'artifact_html_path' => $paths['html'],
            'psp_match' => $match,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildInvoiceLines(Container $container, array $transaction, ?array $listing, array $pspSnapshot): array
    {
        $cfg = $container->get('config');
        $inv = $cfg->get('invoicing', []);
        $notes = is_array($inv) ? ($inv['notes'] ?? []) : [];
        $notes = is_array($notes) ? $notes : [];

        $itemPrice = round((float)($transaction['item_price'] ?? 0), 2);
        $buyerPremium = round((float)($transaction['buyer_protection_fee'] ?? 0), 2);
        $shipping = round((float)($transaction['shipping_cost'] ?? 0), 2);
        $total = round((float)($transaction['total_amount'] ?? 0), 2);

        $hammerNote = (string)($notes['hammer'] ?? 'Pass-through to seller.');
        $marginNote = (string)($notes['margin'] ?? 'Margin scheme may apply to used goods — verify operationally.');

        $marginApplies = $this->marginSchemeApplies($inv, $listing);
        $buyerCountry = strtoupper(trim((string)($transaction['shipping_country'] ?? 'NL')));
        $sellerCountry = strtoupper(trim((string)($transaction['seller_country'] ?? 'NL')));
        $taxCat = (string)($cfg->get('invoicing.tax_category_buyer_premium', 'default'));

        $taxProbe = null;
        if ($buyerPremium > 0) {
            $taxModel = new \App\Domain\Web\Models\TaxModel($container);
            $engine = new TaxEngine($taxModel, $cfg);
            $minor = (int)round($buyerPremium * 100);
            $taxProbe = $engine->calculate([
                'buyer_country' => $buyerCountry,
                'seller_country' => $sellerCountry,
                'category_scope' => $taxCat,
                'seller_type' => 'any',
                'amount_minor' => $minor,
                'basis' => 'net',
                'currency_code' => 'EUR',
                'reference_type' => 'buyer_premium_probe',
                'persist_audit' => false,
            ]);
        }

        $lines = [
            'hammer_pass_through' => [
                'role' => 'hammer_pass_through',
                'amount_eur' => $itemPrice,
                'vat_treatment' => 'not_platform_turnover',
                'note' => $hammerNote,
            ],
            'shipping_component' => [
                'role' => 'shipping_collected',
                'amount_eur' => $shipping,
                'vat_treatment' => 'pass_through_or_carrier_rules',
                'note' => 'Shipping may be netted in seller payout — reconcile with carrier invoices.',
            ],
            'buyer_premium' => [
                'role' => 'commission_buyer_premium',
                'amount_eur' => $buyerPremium,
                'vat_treatment' => 'platform_turnover',
                'note' => 'Platform revenue — subject to VAT per configured rates (see tax_engine probe).',
            ],
            'total_checkout' => [
                'role' => 'checkout_total',
                'amount_eur' => $total,
                'note' => 'Must match PSP captured amount (see psp_amount_match).',
            ],
            'margin_scheme' => [
                'applies' => $marginApplies,
                'note' => $marginApplies ? $marginNote : 'Standard VAT on goods line items if not margin-eligible.',
            ],
            'buyer_premium_tax_probe' => $taxProbe,
        ];

        return $lines;
    }

    /**
     * @param array<string, mixed>|null $listing
     */
    private function marginSchemeApplies(array $invCfg, ?array $listing): bool
    {
        $ms = $invCfg['margin_scheme'] ?? [];
        if (!is_array($ms) || !filter_var($ms['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return false;
        }
        if ($listing === null) {
            return false;
        }
        $allowed = $ms['listing_condition_values'] ?? [];
        if (!is_array($allowed)) {
            return false;
        }
        $cond = strtolower(trim((string)($listing['condition'] ?? '')));

        return $cond !== '' && in_array($cond, array_map('strtolower', $allowed), true);
    }

    /**
     * @param array<string, mixed> $transaction
     *
     * @return array<string, mixed>
     */
    private function selfBillingStub(Container $container, array $transaction): array
    {
        $recv = $this->sellerReceivableMirror($container, $transaction);

        return [
            'seller_id' => (int)($transaction['seller_id'] ?? 0),
            'currency' => 'EUR',
            'net_payable_to_seller_eur' => $recv,
            'narrative' => 'Structured self-billing stub: platform acquisition of goods / payout line — align with your legal templates.',
        ];
    }

    /**
     * Mirrors EscrowService::calculateSellerReceivable for audit consistency.
     *
     * @param array<string, mixed> $transaction
     */
    private function sellerReceivableMirror(Container $container, array $transaction): float
    {
        $itemPrice = (float)($transaction['item_price'] ?? 0.00);
        $shippingCost = (float)($transaction['shipping_cost'] ?? 0.00);
        $gross = round($itemPrice + $shippingCost, 2);

        $rate = (float)$container->get('config')->get('marketplace.fees.seller_platform_rate', 0.03);
        $minimum = (float)$container->get('config')->get('marketplace.fees.seller_platform_min', 0.25);
        $fee = round(max($minimum, $itemPrice * $rate), 2);

        return round(max(0, $gross - $fee), 2);
    }

    /**
     * @param array<string, mixed> $transaction
     * @param array<string, mixed> $pspSnapshot
     *
     * @return array{ok: bool, delta_minor: int, transaction_total_minor: int, psp_minor?: int}
     */
    private function matchPspToTransactionTotal(array $transaction, array $pspSnapshot): array
    {
        $total = round((float)($transaction['total_amount'] ?? 0), 2);
        $txMinor = (int)round($total * 100);
        $pspMinor = (int)($pspSnapshot['amount_minor'] ?? -1);
        if ($pspMinor < 0) {
            return ['ok' => false, 'delta_minor' => -1, 'transaction_total_minor' => $txMinor, 'note' => 'PSP amount unavailable'];
        }

        return [
            'ok' => $pspMinor === $txMinor,
            'delta_minor' => $pspMinor - $txMinor,
            'transaction_total_minor' => $txMinor,
            'psp_minor' => $pspMinor,
        ];
    }

    /**
     * @return array{amount_minor: int, currency: string, method?: string, billing_country?: string, b2b_guess?: bool, raw_source?: string}
     */
    private function fetchPspSnapshot(Container $container, string $paymentRef, string $psp): array
    {
        if ($psp === 'mollie') {
            $svc = new MolliePaymentService($container);
            $r = $svc->fetchPaymentEntity($paymentRef);
            if (!($r['ok'] ?? false) || !isset($r['data']) || !is_array($r['data'])) {
                return ['amount_minor' => -1, 'currency' => 'EUR', 'raw_source' => 'mollie_error'];
            }
            $p = $r['data'];
            $val = isset($p['amount']['value']) ? (float)$p['amount']['value'] : 0.0;
            $cur = strtoupper((string)($p['amount']['currency'] ?? 'EUR'));
            $minor = (int)round($val * 100);
            $ba = $p['billingAddress'] ?? null;
            $billCountry = null;
            if (is_array($ba) && isset($ba['country'])) {
                $billCountry = strtoupper(substr(trim((string)$ba['country']), 0, 2));
            }
            $b2b = false;
            if (is_array($ba)) {
                $b2b = trim((string)($ba['organizationName'] ?? '')) !== '' || trim((string)($ba['companyName'] ?? '')) !== '';
            }

            return [
                'amount_minor' => $minor,
                'currency' => $cur,
                'method' => strtolower((string)($p['method'] ?? '')),
                'billing_country' => $billCountry,
                'b2b_guess' => $b2b,
                'raw_source' => 'mollie_api',
            ];
        }

        if ($psp === 'stripe') {
            $svc = new StripePaymentService($container);
            $r = $svc->fetchPaymentIntent($paymentRef);
            if (!($r['ok'] ?? false) || !isset($r['data']) || !is_array($r['data'])) {
                return ['amount_minor' => -1, 'currency' => 'EUR', 'raw_source' => 'stripe_error'];
            }
            $pi = $r['data'];

            return [
                'amount_minor' => (int)($pi['amount'] ?? 0),
                'currency' => strtoupper((string)($pi['currency'] ?? 'eur')),
                'raw_source' => 'stripe_api',
            ];
        }

        return ['amount_minor' => -1, 'currency' => 'EUR', 'raw_source' => 'unknown_psp'];
    }

    private function resolvePsp(string $paymentReference, string $guess): string
    {
        $g = strtolower(trim($guess));
        if ($g === 'mollie' || $g === 'stripe') {
            return $g;
        }
        if (str_starts_with($paymentReference, 'tr_')) {
            return 'mollie';
        }
        if (str_starts_with($paymentReference, 'pi_')) {
            return 'stripe';
        }

        return 'unknown';
    }

    /**
     * @param array<string, mixed> $lines
     * @param array<string, mixed> $pspSnapshot
     *
     * @return list<string>
     */
    private function complianceNotes(array $lines, array $pspSnapshot, \App\Core\Config $cfg): array
    {
        $out = [];
        $mc = $lines['margin_scheme'] ?? null;
        if (is_array($mc) && ($mc['applies'] ?? false)) {
            $out[] = 'Margin scheme flagged: ensure purchase price evidence and scheme eligibility per EU/national rules.';
        }
        if (($pspSnapshot['b2b_guess'] ?? false) && ($pspSnapshot['billing_country'] ?? '') !== '') {
            $out[] = 'PSP billing suggests B2B — validate reverse charge / VAT ID handling outside this engine.';
        }
        $out[] = (string)$cfg->get('invoicing.notes.self_billing', 'Self-billing legal text is operator-supplied.');

        return $out;
    }

    /**
     * @param array<string, mixed> $lines
     *
     * @return array{reasoning: ?string, html: ?string}
     */
    private function writeArtifacts(Container $container, int $transactionId, array $reasoning, array $lines): array
    {
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 4);
        $sub = (string)$container->get('config')->get('invoicing.artifact_subdir', 'storage/invoices');
        $root = rtrim($base . '/' . trim($sub, '/'), '/');
        if (!is_dir($root) && !@mkdir($root, 0755, true) && !is_dir($root)) {
            return ['reasoning' => null, 'html' => null];
        }
        $reasonDir = $root . '/reasoning';
        $artDir = $root . '/artifacts';
        foreach ([$reasonDir, $artDir] as $d) {
            if (!is_dir($d) && !@mkdir($d, 0755, true) && !is_dir($d)) {
                return ['reasoning' => null, 'html' => null];
            }
        }

        $rPath = $reasonDir . '/transaction-' . $transactionId . '.reasoning.json';
        $json = json_encode($reasoning, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json !== false) {
            @file_put_contents($rPath, $json);
        }

        $html = $this->renderSummaryHtml($transactionId, $lines, $reasoning);
        $hPath = $artDir . '/transaction-' . $transactionId . '.summary.html';
        @file_put_contents($hPath, $html);

        return ['reasoning' => is_file($rPath) ? $rPath : null, 'html' => is_file($hPath) ? $hPath : null];
    }

    /**
     * @param array<string, mixed> $reasoning
     */
    private function renderSummaryHtml(int $transactionId, array $lines, array $reasoning): string
    {
        $safe = static function (string $s): string {
            return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };
        $psp = $reasoning['psp_amount_match'] ?? [];

        $buf = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Invoice summary #' . $transactionId . '</title></head><body>';
        $buf .= '<h1>EU invoice pipeline summary</h1>';
        $buf .= '<p>Transaction <strong>' . $transactionId . '</strong> — artifact for compliance review (not a legal invoice by itself).</p>';
        $buf .= '<h2>PSP match</h2><pre>' . $safe(json_encode($psp, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) . '</pre>';
        $buf .= '<h2>Lines</h2><pre>' . $safe(json_encode($lines, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) . '</pre>';
        $buf .= '<p>Generated ' . $safe((string)($reasoning['generated_at'] ?? '')) . '</p>';
        $buf .= '</body></html>';

        return $buf;
    }
}
