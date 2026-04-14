<?php

declare(strict_types=1);

namespace App\Core\Evolution\Social;

/**
 * GossipService — Sovereign P2P communication layer.
 *
 * Pushes signed "Post" packets to peer Evolution instances.
 * Peers are stored in storage/evolution/social/peers.json.
 *
 * Packet format (JSON, signed by IdentityNode):
 * {
 *   "from":      "<node_id>",
 *   "type":      "post|reputation|ping",
 *   "payload":   { ... },
 *   "timestamp": 1234567890,
 *   "signature": "<base64>"
 * }
 */
final class GossipService
{
    private const PEERS_FILE   = 'storage/evolution/social/peers.json';
    private const INBOX_FILE   = 'storage/evolution/social/inbox.jsonl';
    private const TIMEOUT_SEC  = 5;
    private const GOSSIP_PATH  = '/api/v1/evolution/gossip/receive';

    private string $basePath;
    private IdentityNode $identity;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = $basePath ?? (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 5));
        $this->identity = new IdentityNode($this->basePath);
    }

    /** Push a post to all known peers. Returns ['sent'=>n,'failed'=>n]. */
    public function broadcast(string $type, array $payload): array
    {
        $peers = $this->loadPeers();
        if ($peers === []) {
            return ['sent' => 0, 'failed' => 0, 'peers' => 0];
        }

        $packet = $this->buildPacket($type, $payload);
        $sent = $failed = 0;

        foreach ($peers as $peer) {
            $url = rtrim((string)($peer['url'] ?? ''), '/') . self::GOSSIP_PATH;
            if ($this->push($url, $packet)) {
                $sent++;
            } else {
                $failed++;
            }
        }

        return ['sent' => $sent, 'failed' => $failed, 'peers' => count($peers)];
    }

    /** Receive an inbound gossip packet, verify signature, store in inbox. */
    public function receive(array $packet): array
    {
        if (!$this->verifyPacket($packet)) {
            return ['ok' => false, 'error' => 'Invalid signature'];
        }

        $inboxFile = $this->basePath . '/' . self::INBOX_FILE;
        $dir = dirname($inboxFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }

        $entry = $packet;
        $entry['received_at'] = date('c');
        file_put_contents($inboxFile, json_encode($entry) . "\n", FILE_APPEND | LOCK_EX);

        return ['ok' => true, 'from' => $packet['from'] ?? 'unknown'];
    }

    /** Add a peer to the known-peers list. */
    public function addPeer(string $url, string $nodeId = '', string $alias = ''): void
    {
        $peers = $this->loadPeers();
        foreach ($peers as $p) {
            if ($p['url'] === $url) {
                return;
            }
        }
        $peers[] = [
            'url'        => rtrim($url, '/'),
            'node_id'    => $nodeId,
            'alias'      => $alias,
            'added_at'   => date('c'),
            'last_seen'  => null,
        ];
        $this->savePeers($peers);
    }

    /** Returns all known peers. */
    public function peers(): array
    {
        return $this->loadPeers();
    }

    /** Returns recent inbox items (newest first). */
    public function inbox(int $limit = 50): array
    {
        $inboxFile = $this->basePath . '/' . self::INBOX_FILE;
        if (!is_file($inboxFile)) {
            return [];
        }
        $lines = array_filter(explode("\n", (string) file_get_contents($inboxFile)));
        $items = [];
        foreach ($lines as $line) {
            $d = json_decode($line, true);
            if (is_array($d)) {
                $items[] = $d;
            }
        }
        return array_slice(array_reverse($items), 0, $limit);
    }

    /** Ping all peers, update last_seen. */
    public function pingAll(): array
    {
        $peers  = $this->loadPeers();
        $result = [];
        foreach ($peers as &$peer) {
            $url = rtrim((string)($peer['url'] ?? ''), '/') . self::GOSSIP_PATH;
            $packet = $this->buildPacket('ping', ['msg' => 'hello']);
            $ok = $this->push($url, $packet);
            $peer['last_seen'] = $ok ? date('c') : ($peer['last_seen'] ?? null);
            $result[] = ['url' => $peer['url'], 'ok' => $ok];
        }
        unset($peer);
        $this->savePeers($peers);
        return $result;
    }

    private function buildPacket(string $type, array $payload): array
    {
        $ts = time();
        $nodeId = $this->identity->nodeId();
        $body = json_encode(['from' => $nodeId, 'type' => $type, 'payload' => $payload, 'timestamp' => $ts]);
        $signature = $this->identity->sign((string) $body);
        return [
            'from'      => $nodeId,
            'type'      => $type,
            'payload'   => $payload,
            'timestamp' => $ts,
            'signature' => $signature,
        ];
    }

    private function verifyPacket(array $packet): bool
    {
        $sig  = $packet['signature'] ?? '';
        $from = $packet['from'] ?? '';
        if ($sig === '' || $from === '') {
            return false;
        }

        $check = $packet;
        unset($check['signature'], $check['received_at']);
        $body = json_encode($check);

        $peers = $this->loadPeers();
        foreach ($peers as $peer) {
            if (($peer['node_id'] ?? '') === $from && isset($peer['public_key'])) {
                return IdentityNode::verify((string) $body, $sig, $peer['public_key']);
            }
        }

        return false;
    }

    private function push(string $url, array $packet): bool
    {
        $json = json_encode($packet);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-Evolution-Gossip: 1'],
            CURLOPT_TIMEOUT        => self::TIMEOUT_SEC,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $status >= 200 && $status < 300;
    }

    private function loadPeers(): array
    {
        $file = $this->basePath . '/' . self::PEERS_FILE;
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    private function savePeers(array $peers): void
    {
        $file = $this->basePath . '/' . self::PEERS_FILE;
        $dir  = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        file_put_contents($file, json_encode($peers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }
}
