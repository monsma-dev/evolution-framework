<?php

declare(strict_types=1);

namespace App\Core\Evolution\Affiliate\Tools;

use App\Core\Evolution\Affiliate\EntitySettingsManager;
use Psr\Container\ContainerInterface;

/**
 * InvoicingTool — Genereert officiële facturen / kwitanties als HTML.
 *
 * Logica op basis van EntitySettingsManager:
 *   Mode = particulier → Kwitantie, 0% BTW, clausule "Vrijgesteld van BTW…"
 *   Mode = bedrijf     → Factuur, 21% BTW toegevoegd, KvK + BTW-nummer vermeld
 *
 * Output: HTML-string klaar voor browser-print of opslaan als draft in affiliate_content.
 * Druk op Ctrl+P in de browser voor PDF-export (geen externe library nodig).
 *
 * Gebruik:
 *   $tool = new InvoicingTool($container);
 *   $result = $tool->generate($opportunityId, 'Lead conversie', 500.00);
 *   // result['html'] = printbare HTML, result['document_id'] = opgeslagen in DB
 */
final class InvoicingTool
{
    private EntitySettingsManager $entity;
    private ?\PDO                 $db;
    private string                $basePath;

    public function __construct(?ContainerInterface $container = null, ?string $basePath = null)
    {
        $this->basePath = $basePath ?? (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 5));
        $this->entity   = new EntitySettingsManager($container, $this->basePath);
        $this->db       = null;

        if ($container !== null) {
            try {
                $this->db = $container->get('db');
            } catch (\Throwable) {
            }
        }
    }

    /**
     * Genereer een factuur/kwitantie.
     *
     * @param int    $opportunityId  Affiliate opportunity ID (0 = los document)
     * @param string $description    Omschrijving van de dienst/verkoop
     * @param float  $netAmountEur   Netto bedrag (excl. BTW in bedrijfsmodus)
     * @param string $recipientName  Naam van ontvanger/klant
     * @param string $recipientEmail E-mail ontvanger
     *
     * @return array{ok: bool, document_id: ?int, html: string, amount_net: float,
     *               amount_vat: float, amount_total: float, document_type: string}
     */
    public function generate(
        int    $opportunityId,
        string $description,
        float  $netAmountEur,
        string $recipientName  = '',
        string $recipientEmail = ''
    ): array {
        $isBedrijf  = $this->entity->isBedrijf();
        $vatAmount  = $isBedrijf ? round($netAmountEur * EntitySettingsManager::BTW_RATE, 2) : 0.0;
        $totalEur   = round($netAmountEur + $vatAmount, 2);
        $docType    = $isBedrijf ? 'Factuur' : 'Kwitantie';
        $invoiceNum = $this->generateInvoiceNumber();

        if ($isBedrijf) {
            $this->entity->addToTaxReserve($totalEur);
        }

        $html       = $this->renderHtml(
            $docType,
            $invoiceNum,
            $description,
            $netAmountEur,
            $vatAmount,
            $totalEur,
            $recipientName,
            $recipientEmail
        );

        $docId = $this->saveDocument(
            $opportunityId,
            $invoiceNum,
            $docType,
            $description,
            $netAmountEur,
            $vatAmount,
            $totalEur,
            $recipientName,
            $html
        );

        return [
            'ok'            => true,
            'document_id'   => $docId,
            'html'          => $html,
            'amount_net'    => $netAmountEur,
            'amount_vat'    => $vatAmount,
            'amount_total'  => $totalEur,
            'document_type' => $docType,
            'invoice_number'=> $invoiceNum,
        ];
    }

    /** Haal opgeslagen documenten op voor een opportunity. */
    public function getDocuments(int $opportunityId, int $limit = 20): array
    {
        if ($this->db === null) {
            return [];
        }

        try {
            $stmt = $this->db->prepare(
                'SELECT id, invoice_number, document_type, description, amount_net, amount_vat, amount_total,
                        recipient_name, status, created_at
                 FROM affiliate_invoices
                 WHERE opportunity_id = :oid
                 ORDER BY created_at DESC
                 LIMIT :lim'
            );
            $stmt->bindValue(':oid', $opportunityId, \PDO::PARAM_INT);
            $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return [];
        }
    }

    /** Haal HTML van een specifiek document op. */
    public function getHtml(int $documentId): ?string
    {
        if ($this->db === null) {
            return null;
        }

        try {
            $stmt = $this->db->prepare('SELECT html_content FROM affiliate_invoices WHERE id = :id');
            $stmt->execute([':id' => $documentId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            return $row ? (string)$row['html_content'] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    // ── Private ────────────────────────────────────────────────────────────

    private function generateInvoiceNumber(): string
    {
        $prefix = $this->entity->isBedrijf() ? 'F' : 'K';
        return $prefix . date('Y') . '-' . str_pad((string)(time() % 100000), 5, '0', STR_PAD_LEFT);
    }

    private function renderHtml(
        string $docType,
        string $invoiceNum,
        string $description,
        float  $netEur,
        float  $vatEur,
        float  $totalEur,
        string $recipientName,
        string $recipientEmail
    ): string {
        $entity     = $this->entity->all();
        $isBedrijf  = $this->entity->isBedrijf();
        $senderName = $this->entity->displayName();
        $iban       = htmlspecialchars($entity['framework_entity_iban'] ?? '', ENT_QUOTES);
        $kvk        = htmlspecialchars($entity['framework_entity_kvk']  ?? '', ENT_QUOTES);
        $btwNr      = htmlspecialchars($entity['framework_entity_btw']  ?? '', ENT_QUOTES);
        $vatClause  = htmlspecialchars($this->entity->vatClause(), ENT_QUOTES, 'UTF-8', false);
        $date       = date('d-m-Y');
        $dueDate    = date('d-m-Y', strtotime('+30 days'));

        $recipientBlock = '';
        if ($recipientName !== '') {
            $recipientBlock = '<p><strong>Aan:</strong> ' . htmlspecialchars($recipientName, ENT_QUOTES) . '</p>';
        }
        if ($recipientEmail !== '') {
            $recipientBlock .= '<p>' . htmlspecialchars($recipientEmail, ENT_QUOTES) . '</p>';
        }

        $vatRow = $isBedrijf
            ? '<tr><td>BTW (21%)</td><td>€ ' . number_format($vatEur, 2, ',', '.') . '</td></tr>'
            : '';

        $companyFields = $isBedrijf && ($kvk !== '' || $btwNr !== '')
            ? ($kvk !== '' ? "<p>KvK-nummer: {$kvk}</p>" : '')
              . ($btwNr !== '' ? "<p>BTW-nummer: {$btwNr}</p>" : '')
            : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<title>{$docType} {$invoiceNum}</title>
<style>
  @media print { .no-print { display: none; } body { margin: 0; } }
  body { font-family: Arial, Helvetica, sans-serif; font-size: 14px; color: #1a1a2e; margin: 40px; background: #fff; }
  .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 32px; border-bottom: 3px solid #1a1a2e; padding-bottom: 16px; }
  .doc-title { font-size: 28px; font-weight: 700; color: #1a1a2e; }
  .doc-meta { text-align: right; color: #555; }
  .sender { margin-bottom: 24px; }
  .recipient { background: #f4f6fb; border-left: 4px solid #1a1a2e; padding: 12px 16px; margin-bottom: 24px; }
  table { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
  table th { background: #1a1a2e; color: #fff; padding: 10px 12px; text-align: left; }
  table td { padding: 10px 12px; border-bottom: 1px solid #e5e7eb; }
  .totals { width: 320px; margin-left: auto; }
  .totals td { padding: 6px 12px; }
  .totals .total-row td { font-weight: 700; font-size: 16px; border-top: 2px solid #1a1a2e; }
  .vat-clause { font-size: 12px; color: #666; border-top: 1px solid #e5e7eb; padding-top: 12px; margin-top: 24px; }
  .iban-block { margin-top: 16px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; padding: 12px 16px; }
  .btn-print { display: inline-block; margin-bottom: 24px; padding: 10px 24px; background: #1a1a2e; color: #fff; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 600; }
</style>
</head>
<body>
<button class="btn-print no-print" onclick="window.print()">🖨 Afdrukken / Opslaan als PDF</button>

<div class="header">
  <div>
    <div class="doc-title">{$docType}</div>
    <div style="color:#555;margin-top:4px;">{$invoiceNum}</div>
  </div>
  <div class="doc-meta">
    <p><strong>Datum:</strong> {$date}</p>
    <p><strong>Vervaldatum:</strong> {$dueDate}</p>
  </div>
</div>

<div class="sender">
  <p><strong>{$senderName}</strong></p>
  {$companyFields}
  {$iban}
</div>

<div class="recipient">
  {$recipientBlock}
</div>

<table>
  <thead>
    <tr>
      <th>Omschrijving</th>
      <th>Bedrag</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td>{$description}</td>
      <td>€ {$this->fmt($netEur)}</td>
    </tr>
  </tbody>
</table>

<table class="totals">
  <tbody>
    <tr><td>Subtotaal</td><td>€ {$this->fmt($netEur)}</td></tr>
    {$vatRow}
    <tr class="total-row"><td>Totaal</td><td>€ {$this->fmt($totalEur)}</td></tr>
  </tbody>
</table>

<div class="vat-clause">{$vatClause}</div>

<div class="iban-block">
  <strong>Betaalinstructies:</strong><br>
  Maak het bedrag van <strong>€ {$this->fmt($totalEur)}</strong> over naar:<br>
  IBAN: <strong>{$iban}</strong><br>
  Onder vermelding van: <strong>{$invoiceNum}</strong>
</div>

</body>
</html>
HTML;
    }

    private function fmt(float $amount): string
    {
        return number_format($amount, 2, ',', '.');
    }

    private function saveDocument(
        int    $opportunityId,
        string $invoiceNum,
        string $docType,
        string $description,
        float  $netEur,
        float  $vatEur,
        float  $totalEur,
        string $recipientName,
        string $html
    ): ?int {
        if ($this->db === null) {
            return null;
        }

        try {
            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS affiliate_invoices (
                    id              INT AUTO_INCREMENT PRIMARY KEY,
                    opportunity_id  INT NOT NULL DEFAULT 0,
                    invoice_number  VARCHAR(50) NOT NULL,
                    document_type   VARCHAR(20) NOT NULL DEFAULT 'Kwitantie',
                    description     TEXT,
                    amount_net      DECIMAL(10,2) NOT NULL DEFAULT 0,
                    amount_vat      DECIMAL(10,2) NOT NULL DEFAULT 0,
                    amount_total    DECIMAL(10,2) NOT NULL DEFAULT 0,
                    recipient_name  VARCHAR(255) DEFAULT '',
                    html_content    LONGTEXT,
                    status          VARCHAR(20)  NOT NULL DEFAULT 'draft',
                    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );

            $stmt = $this->db->prepare(
                'INSERT INTO affiliate_invoices
                 (opportunity_id, invoice_number, document_type, description,
                  amount_net, amount_vat, amount_total, recipient_name, html_content, status)
                 VALUES (:oid,:num,:type,:desc,:net,:vat,:total,:rcpt,:html,"draft")'
            );
            $stmt->execute([
                ':oid'   => $opportunityId,
                ':num'   => $invoiceNum,
                ':type'  => $docType,
                ':desc'  => mb_substr($description, 0, 1000),
                ':net'   => $netEur,
                ':vat'   => $vatEur,
                ':total' => $totalEur,
                ':rcpt'  => mb_substr($recipientName, 0, 255),
                ':html'  => $html,
            ]);

            return (int)$this->db->lastInsertId();
        } catch (\Throwable) {
            return null;
        }
    }
}
