<?php

namespace App\NetFlow;

class NetFlowTemplate
{
    /**
     * @param list<NetFlowField> $fields
     */
    public function __construct(
        public readonly string $exporterIp,
        public readonly int $sourceId,
        public readonly int $templateId,
        public readonly array $fields,
    ) {
    }

    public function recordLength(): int
    {
        $length = 0;

        foreach ($this->fields as $field) {
            $length += $field->length;
        }

        return $length;
    }

    public function key(): string
    {
        return self::buildKey($this->exporterIp, $this->sourceId, $this->templateId);
    }

    public static function buildKey(string $exporterIp, int $sourceId, int $templateId): string
    {
        return sprintf('%s:%d:%d', $exporterIp, $sourceId, $templateId);
    }
}
