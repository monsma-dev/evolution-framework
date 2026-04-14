<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Evolution\Social\GossipService;
use App\Core\Evolution\Social\IdentityNode;
use App\Core\Evolution\Social\IntelligenceFeed;

/**
 * evolve:social-node — Manage this server's Sovereign Social Identity
 *
 * Usage:
 *   php ai_bridge.php evolve:social-node init
 *   php ai_bridge.php evolve:social-node status
 *   php ai_bridge.php evolve:social-node sign "message"
 *   php ai_bridge.php evolve:social-node peer:add <url> [alias]
 *   php ai_bridge.php evolve:social-node peer:list
 *   php ai_bridge.php evolve:social-node peer:ping
 *   php ai_bridge.php evolve:social-node feed [--threshold=0.75]
 *   php ai_bridge.php evolve:social-node interests:set topic1,topic2,...
 *   php ai_bridge.php evolve:social-node post "title" "content"
 */
final class EvolutionSocialNodeCommand
{
    private IdentityNode    $identity;
    private GossipService   $gossip;
    private IntelligenceFeed $feed;

    public function __construct()
    {
        $this->identity = new IdentityNode();
        $this->gossip   = new GossipService();
        $this->feed     = new IntelligenceFeed();
    }

    public function run(array $args): int
    {
        $sub = $args[0] ?? 'status';

        return match ($sub) {
            'init'          => $this->init(),
            'status'        => $this->status(),
            'sign'          => $this->sign($args[1] ?? ''),
            'peer:add'      => $this->peerAdd($args[1] ?? '', $args[2] ?? ''),
            'peer:list'     => $this->peerList(),
            'peer:ping'     => $this->peerPing(),
            'feed'          => $this->showFeed($args),
            'interests:set' => $this->setInterests($args[1] ?? ''),
            'post'          => $this->post($args[1] ?? '', $args[2] ?? ''),
            default         => $this->usage(),
        };
    }

    private function init(): int
    {
        if ($this->identity->exists()) {
            $id = $this->identity->nodeId();
            echo "Identity already exists. Node ID: {$id}\n";
            echo "Use 'evolve:social-node status' to view details.\n";
            return 0;
        }

        echo "Generating 4096-bit RSA keypair...\n";
        $identity = $this->identity->generate();
        echo "\n✓ Sovereign Identity created\n";
        echo "  Node ID  : {$identity['node_id']}\n";
        echo "  Created  : {$identity['created_at']}\n";
        echo "  Key size : 4096-bit RSA\n";
        echo "\nPublic key stored in storage/evolution/social/identity.json\n";
        echo "Private key stored in storage/evolution/social/identity.key (chmod 600)\n";
        return 0;
    }

    private function status(): int
    {
        if (!$this->identity->exists()) {
            echo "No identity found. Run: php ai_bridge.php evolve:social-node init\n";
            return 1;
        }
        $id   = $this->identity->identity();
        $peers = $this->gossip->peers();
        echo "=== Sovereign Node Status ===\n";
        echo "Node ID  : {$id['node_id']}\n";
        echo "Created  : {$id['created_at']}\n";
        echo "Peers    : " . count($peers) . " known\n";
        echo "Feed     : " . count($this->feed->interests()) . " interest topics\n";
        $inbox = $this->gossip->inbox(999);
        $posts = array_filter($inbox, static fn($p) => ($p['type'] ?? '') === 'post');
        echo "Inbox    : " . count($inbox) . " packets (" . count($posts) . " posts)\n";
        return 0;
    }

    private function sign(string $message): int
    {
        if ($message === '') {
            echo "Usage: evolve:social-node sign \"your message\"\n";
            return 1;
        }
        $sig = $this->identity->sign($message);
        echo "Message  : {$message}\n";
        echo "Node ID  : {$this->identity->nodeId()}\n";
        echo "Signature: {$sig}\n";
        return 0;
    }

    private function peerAdd(string $url, string $alias): int
    {
        if ($url === '') {
            echo "Usage: evolve:social-node peer:add <url> [alias]\n";
            return 1;
        }
        $this->gossip->addPeer($url, '', $alias);
        echo "Peer added: {$url}" . ($alias !== '' ? " ({$alias})" : '') . "\n";
        echo "Tip: share your public key so they can verify your posts.\n";
        return 0;
    }

    private function peerList(): int
    {
        $peers = $this->gossip->peers();
        if ($peers === []) {
            echo "No peers configured. Use: evolve:social-node peer:add <url>\n";
            return 0;
        }
        echo str_pad('URL', 50) . str_pad('Alias', 20) . str_pad('Last Seen', 25) . "Node ID\n";
        echo str_repeat('-', 120) . "\n";
        foreach ($peers as $p) {
            echo str_pad($p['url'] ?? '-', 50)
                . str_pad($p['alias'] ?? '-', 20)
                . str_pad($p['last_seen'] ?? 'never', 25)
                . substr($p['node_id'] ?? '-', 0, 16) . "...\n";
        }
        return 0;
    }

    private function peerPing(): int
    {
        $peers = $this->gossip->peers();
        if ($peers === []) {
            echo "No peers to ping.\n";
            return 0;
        }
        echo "Pinging " . count($peers) . " peer(s)...\n";
        $results = $this->gossip->pingAll();
        foreach ($results as $r) {
            $status = $r['ok'] ? '✓ online' : '✗ offline';
            echo "  {$status}  {$r['url']}\n";
        }
        return 0;
    }

    private function showFeed(array $args): int
    {
        $threshold = self::DEFAULT_THRESHOLD;
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--threshold=')) {
                $threshold = (float) substr($arg, 12);
            }
        }
        $feed = new IntelligenceFeed(null, $threshold);
        $items = $feed->feed();
        if ($items === []) {
            echo "No posts in feed matching threshold {$threshold}.\n";
            echo "Use 'evolve:social-node interests:set' to configure your topics.\n";
            return 0;
        }
        echo "=== Intelligence Feed (threshold={$threshold}) ===\n";
        foreach ($items as $item) {
            $p = $item['post']['payload'] ?? [];
            echo "\n[{$item['score']}] " . ($p['title'] ?? $p['text'] ?? 'Untitled') . "\n";
            echo "  From     : " . substr($item['post']['from'] ?? '?', 0, 16) . "...\n";
            echo "  Topic    : " . ($item['matched_topic'] ?: 'general') . "\n";
            echo "  Received : " . ($item['post']['received_at'] ?? $item['post']['timestamp'] ?? '?') . "\n";
        }
        return 0;
    }

    private function setInterests(string $csv): int
    {
        if ($csv === '') {
            echo "Usage: evolve:social-node interests:set topic1,topic2,topic3\n";
            echo "Current: " . implode(', ', $this->feed->interests()) . "\n";
            return 1;
        }
        $topics = array_filter(array_map('trim', explode(',', $csv)));
        $this->feed->setInterests(array_values($topics));
        echo "Interests updated: " . implode(', ', $topics) . "\n";
        return 0;
    }

    private function post(string $title, string $content): int
    {
        if ($title === '') {
            echo "Usage: evolve:social-node post \"title\" \"content\"\n";
            return 1;
        }
        $result = $this->gossip->broadcast('post', [
            'title'   => $title,
            'content' => $content,
            'text'    => "{$title} {$content}",
        ]);
        echo "Post broadcast: sent={$result['sent']}, failed={$result['failed']}, peers={$result['peers']}\n";
        return 0;
    }

    private function usage(): int
    {
        echo <<<USAGE
evolve:social-node — Sovereign Social Mesh

Subcommands:
  init                      Generate this server's identity keypair
  status                    Show node status, peers, inbox
  sign "message"            Sign a message with your private key
  peer:add <url> [alias]    Add a peer Evolution instance
  peer:list                 List all known peers
  peer:ping                 Ping all peers
  feed [--threshold=0.75]   Show AI-filtered intelligence feed
  interests:set a,b,c       Set your interest topics for feed filtering
  post "title" "content"    Broadcast a post to all peers

USAGE;
        return 0;
    }

    private const DEFAULT_THRESHOLD = 0.75;
}
