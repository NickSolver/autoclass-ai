<?php

namespace App\Domain\Workflow\Graph;

/**
 * Граф процесса (JSON версии): узлы {id, type, position, data} и связи {id, source, sourceHandle, target}.
 * Формат совместим с редактором Vue Flow; сервер хранит его как есть и сам проверяет смысл.
 */
final class Graph
{
    /** @var array<string, array{id: string, type: string, data: array<string, mixed>}> */
    public readonly array $nodes;

    /** @var array<string, array<string, string>> source → handle → target */
    public readonly array $edges;

    /** @param array<string, mixed> $json */
    public function __construct(array $json)
    {
        $nodes = [];
        foreach ((array) ($json['nodes'] ?? []) as $node) {
            if (is_array($node) && isset($node['id'], $node['type'])) {
                $nodes[(string) $node['id']] = ['id' => (string) $node['id'], 'type' => (string) $node['type'], 'data' => (array) ($node['data'] ?? [])];
            }
        }
        $edges = [];
        foreach ((array) ($json['edges'] ?? []) as $edge) {
            if (is_array($edge) && isset($edge['source'], $edge['target'])) {
                $edges[(string) $edge['source']][(string) ($edge['sourceHandle'] ?? 'out') ?: 'out'] = (string) $edge['target'];
            }
        }
        $this->nodes = $nodes;
        $this->edges = $edges;
    }

    public function start(): ?string
    {
        foreach ($this->nodes as $id => $node) {
            if ($node['type'] === 'start') {
                return $id;
            }
        }

        return null;
    }

    public function next(string $nodeId, string $handle): ?string
    {
        return $this->edges[$nodeId][$handle] ?? null;
    }
}
