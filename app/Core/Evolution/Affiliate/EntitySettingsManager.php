<?php

declare(strict_types=1);

namespace App\Core\Evolution\Affiliate;

use Psr\Container\ContainerInterface;

/**
 * EntitySettingsManager — Beheert de juridische entiteitsmodus van het framework.
 *
 * Modi:
 *   particulier  — Werkt als privépersoon. Facturen zijn kwitanties (0% BTW).
 *   bedrijf      — Werkt namens een bedrijf. Facturen bevatten 21% BTW + KvK/BTW-nr.
 *
 * Instellingen worden opgeslagen in de framework_settings tabel.
 * Fallback: JSON-bestand in storage/evolution/entity_settings.json.
 */
final class EntitySettingsManager
{
    public const MODE_PARTICULIER = 'particulier';
    public const MODE_BEDRIJF     = 'bedrijf';
    public const BTW_RATE         = 0.21;

    private const SETTINGS_KEYS = [
        'framework_entity_mode',
        'framework_entity_name',
        'framework_entity_iban',
        'framework_entity_kvk',
        'framework_entity_btw',
        'framework_entity_company',
        'framework_tax_reserve_eur',
    ];

    private const FALLBACK_FILE = 'storage/evolution/entity_settings.json';

    private ?\PDO   $db;
    private string  $basePath;

    public function __construct(?ContainerInterface $container = null, ?string $basePath = null)
    {
        $this->basePath = $basePath ?? (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 4));
        $this->db       = null;

        if ($container !== null) {
            try {
                $this->db = $container->get('db');
            } catch (\Throwable) {
            }
        }
    }

    /** Haal alle instellingen op als array. */
    public function all(): array
    {
        $settings = $this->loadFromDb();
        if ($settings !== null) {
            return $settings;
        }

        return $this->loadFromFile();
    }

    /** Haal één instelling op. */
    public function get(string $key, string $default = ''): string
    {
        return (string)($this->all()[$key] ?? $default);
    }

    /** Huidige modus: 'particulier' of 'bedrijf'. */
    public function mode(): string
    {
        $mode = $this->get('framework_entity_mode', self::MODE_PARTICULIER);
        return in_array($mode, [self::MODE_PARTICULIER, self::MODE_BEDRIJF], true)
            ? $mode
            : self::MODE_PARTICULIER;
    }

    public function isBedrijf(): bool
    {
        return $this->mode() === self::MODE_BEDRIJF;
    }

    public function isParticulier(): bool
    {
        return $this->mode() === self::MODE_PARTICULIER;
    }

    /** Sla één of meerdere instellingen op. */
    public function save(array $settings): bool
    {
        $ok = $this->saveToDb($settings);
        $this->saveToFile(array_merge($this->loadFromFile(), $settings));

        return $ok;
    }

    /** Voeg een bedrag toe aan de fiscale BTW-reserve (alleen in bedrijfsmodus). */
    public function addToTaxReserve(float $grossAmountEur): float
    {
        if (!$this->isBedrijf()) {
            return 0.0;
        }

        $reserveAmount  = round($grossAmountEur * self::BTW_RATE, 2);
        $current        = (float)$this->get('framework_tax_reserve_eur', '0');
        $newReserve     = round($current + $reserveAmount, 2);

        $this->save(['framework_tax_reserve_eur' => (string)$newReserve]);

        return $reserveAmount;
    }

    /** Haal de huidige fiscale BTW-reserve op. */
    public function getTaxReserveEur(): float
    {
        return (float)$this->get('framework_tax_reserve_eur', '0');
    }

    /** Display naam (persoonsnaam of bedrijfsnaam). */
    public function displayName(): string
    {
        if ($this->isBedrijf()) {
            $company = $this->get('framework_entity_company');
            if ($company !== '') {
                return $company;
            }
        }

        return $this->get('framework_entity_name', 'Onbekend');
    }

    /** Geeft de BTW-clausule voor kwitanties/facturen. */
    public function vatClause(): string
    {
        if ($this->isParticulier()) {
            return 'Vrijgesteld van BTW wegens het niet zijn van een ondernemer voor de omzetbelasting.';
        }

        $btwNr = $this->get('framework_entity_btw');
        return $btwNr !== ''
            ? "BTW-nummer: {$btwNr} — 21% BTW inbegrepen."
            : '21% BTW inbegrepen.';
    }

    // ── Private helpers ────────────────────────────────────────────────────

    private function loadFromDb(): ?array
    {
        if ($this->db === null) {
            return null;
        }

        try {
            $in  = implode(',', array_fill(0, count(self::SETTINGS_KEYS), '?'));
            $stmt = $this->db->prepare(
                "SELECT setting_key, setting_value FROM framework_settings WHERE setting_key IN ({$in})"
            );
            $stmt->execute(self::SETTINGS_KEYS);
            $rows = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);

            if (empty($rows)) {
                return null;
            }

            return array_merge(array_fill_keys(self::SETTINGS_KEYS, ''), $rows);
        } catch (\Throwable) {
            return null;
        }
    }

    private function saveToDb(array $settings): bool
    {
        if ($this->db === null) {
            return false;
        }

        try {
            $stmt = $this->db->prepare(
                'INSERT INTO framework_settings (setting_key, setting_value)
                 VALUES (:k, :v)
                 ON DUPLICATE KEY UPDATE setting_value = :v2'
            );

            foreach ($settings as $key => $value) {
                if (!in_array($key, self::SETTINGS_KEYS, true)) {
                    continue;
                }
                $stmt->execute([':k' => $key, ':v' => (string)$value, ':v2' => (string)$value]);
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function loadFromFile(): array
    {
        $path = $this->basePath . '/' . self::FALLBACK_FILE;
        if (!is_file($path)) {
            return array_fill_keys(self::SETTINGS_KEYS, '');
        }

        $data = json_decode((string)file_get_contents($path), true);
        if (!is_array($data)) {
            return array_fill_keys(self::SETTINGS_KEYS, '');
        }

        return array_merge(array_fill_keys(self::SETTINGS_KEYS, ''), $data);
    }

    private function saveToFile(array $settings): void
    {
        $dir  = $this->basePath . '/data/evolution';
        $path = $this->basePath . '/' . self::FALLBACK_FILE;

        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        @file_put_contents($path, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n", LOCK_EX);
    }
}
