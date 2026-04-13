<?php

declare(strict_types=1);

namespace App\Core\Evolution;

/**
 * Gegooid na een fatale fout tijdens auto-apply nadat Respawn + snapshot-restore zijn uitgevoerd.
 * De frontend kan dit als rollback-signaal tonen.
 */
final class EvolutionFatalException extends \RuntimeException
{
    /** @var array<string, mixed> */
    private array $snapshotRestore;

    /**
     * @param array<string, mixed> $snapshotRestore
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        array $snapshotRestore = []
    ) {
        parent::__construct($message, $code, $previous);
        $this->snapshotRestore = $snapshotRestore;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSnapshotRestore(): array
    {
        return $this->snapshotRestore;
    }
}
