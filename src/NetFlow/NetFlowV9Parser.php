<?php

namespace App\NetFlow;

use DateTimeImmutable;
use RuntimeException;

class NetFlowV9Parser
{
    private const HEADER_LENGTH = 20;
    private const TEMPLATE_FLOWSET_ID = 0;
    private const OPTIONS_TEMPLATE_FLOWSET_ID = 1;
    private const DATA_FLOWSET_MIN_ID = 256;

    /** @var array<string, NetFlowTemplate> */
    private array $templates = [];

    /** @var list<string> */
    private array $lastWarnings = [];

    private int $lastParsedTemplateCount = 0;

    /**
     * @return list<ParsedFlow>
     */
    public function parse(string $packet, string $exporterIp): array
    {
        $this->lastWarnings = [];
        $this->lastParsedTemplateCount = 0;

        $packetLength = strlen($packet);
        if ($packetLength < self::HEADER_LENGTH) {
            $this->lastWarnings[] = sprintf('Packet too short: %d bytes', $packetLength);

            return [];
        }

        $header = unpack('nversion/ncount/NsysUptime/NunixSecs/NsequenceNumber/NsourceId', substr($packet, 0, self::HEADER_LENGTH));
        if ($header === false) {
            $this->lastWarnings[] = 'Unable to parse NetFlow v9 header';

            return [];
        }

        if ($header['version'] !== 9) {
            throw new RuntimeException(sprintf('Unsupported NetFlow version %d, expected 9', $header['version']));
        }

        $flows = [];
        $offset = self::HEADER_LENGTH;

        while ($offset + 4 <= $packetLength) {
            $flowSetHeader = unpack('nflowSetId/nlength', substr($packet, $offset, 4));
            if ($flowSetHeader === false) {
                $this->lastWarnings[] = 'Unable to parse flowset header';
                break;
            }

            $flowSetId = $flowSetHeader['flowSetId'];
            $flowSetLength = $flowSetHeader['length'];

            if ($flowSetLength < 4) {
                $this->lastWarnings[] = sprintf('Flowset length invalid: %d', $flowSetLength);
                break;
            }

            if ($offset + $flowSetLength > $packetLength) {
                $this->lastWarnings[] = sprintf(
                    'Flowset length invalid: offset=%d length=%d packetLength=%d',
                    $offset,
                    $flowSetLength,
                    $packetLength
                );
                break;
            }

            $payloadOffset = $offset + 4;
            $payloadLength = $flowSetLength - 4;

            if ($flowSetId === self::TEMPLATE_FLOWSET_ID) {
                $this->parseTemplateFlowSet($packet, $payloadOffset, $payloadLength, $exporterIp, $header['sourceId']);
            } elseif ($flowSetId === self::OPTIONS_TEMPLATE_FLOWSET_ID) {
                $this->lastWarnings[] = 'Options Template FlowSet skipped';
            } elseif ($flowSetId >= self::DATA_FLOWSET_MIN_ID) {
                $flows = array_merge(
                    $flows,
                    $this->parseDataFlowSet($packet, $payloadOffset, $payloadLength, $exporterIp, $header['sourceId'], $flowSetId, $header)
                );
            } else {
                $this->lastWarnings[] = sprintf('Unsupported FlowSet ID %d skipped', $flowSetId);
            }

            $offset += $flowSetLength;
        }

        return $flows;
    }

    /**
     * @return list<string>
     */
    public function lastWarnings(): array
    {
        return $this->lastWarnings;
    }

    public function parsedTemplatesInLastPacket(): int
    {
        return $this->lastParsedTemplateCount;
    }

    private function parseTemplateFlowSet(string $packet, int $payloadOffset, int $payloadLength, string $exporterIp, int $sourceId): void
    {
        $offset = $payloadOffset;
        $end = $payloadOffset + $payloadLength;

        while ($offset + 4 <= $end) {
            $templateHeader = unpack('ntemplateId/nfieldCount', substr($packet, $offset, 4));
            if ($templateHeader === false) {
                $this->lastWarnings[] = 'Unable to parse template header';
                break;
            }

            $templateId = $templateHeader['templateId'];
            $fieldCount = $templateHeader['fieldCount'];
            $offset += 4;

            if ($templateId === 0 && $fieldCount === 0) {
                break;
            }

            if ($templateId < self::DATA_FLOWSET_MIN_ID) {
                $this->lastWarnings[] = sprintf('Template ID %d is invalid', $templateId);
                break;
            }

            $fieldsLength = $fieldCount * 4;
            if ($offset + $fieldsLength > $end) {
                $this->lastWarnings[] = sprintf('Template %d is incomplete', $templateId);
                break;
            }

            $fields = [];
            for ($i = 0; $i < $fieldCount; $i++) {
                $field = unpack('ntype/nlength', substr($packet, $offset, 4));
                if ($field === false) {
                    $this->lastWarnings[] = sprintf('Unable to parse field %d for template %d', $i + 1, $templateId);
                    break 2;
                }

                $fields[] = new NetFlowField($field['type'], $field['length']);
                $offset += 4;
            }

            $template = new NetFlowTemplate($exporterIp, $sourceId, $templateId, $fields);
            $this->templates[$template->key()] = $template;
            $this->lastParsedTemplateCount++;
        }
    }

    /**
     * @param array<string, int> $header
     *
     * @return list<ParsedFlow>
     */
    private function parseDataFlowSet(
        string $packet,
        int $payloadOffset,
        int $payloadLength,
        string $exporterIp,
        int $sourceId,
        int $templateId,
        array $header,
    ): array {
        $templateKey = NetFlowTemplate::buildKey($exporterIp, $sourceId, $templateId);
        $template = $this->templates[$templateKey] ?? null;

        if ($template === null) {
            $this->lastWarnings[] = sprintf('Unknown template, skipping data flowset: templateId=%d sourceId=%d exporter=%s', $templateId, $sourceId, $exporterIp);

            return [];
        }

        $recordLength = $template->recordLength();
        if ($recordLength <= 0) {
            $this->lastWarnings[] = sprintf('Template %d has invalid record length %d', $templateId, $recordLength);

            return [];
        }

        $flows = [];
        $offset = $payloadOffset;
        $end = $payloadOffset + $payloadLength;

        while ($offset + $recordLength <= $end) {
            $fields = $this->parseRecordFields($packet, $offset, $template);
            $flows[] = $this->buildParsedFlow($exporterIp, $sourceId, $fields, $header);
            $offset += $recordLength;
        }

        $remaining = $end - $offset;
        if ($remaining > 0 && trim(substr($packet, $offset, $remaining), "\0") !== '') {
            $this->lastWarnings[] = sprintf('Record incomplete, skipped %d trailing bytes', $remaining);
        }

        return $flows;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseRecordFields(string $packet, int $recordOffset, NetFlowTemplate $template): array
    {
        $fields = [];
        $offset = $recordOffset;

        foreach ($template->fields as $field) {
            $value = $this->decodeField($field, substr($packet, $offset, $field->length));
            $fields[$field->alias()] = $value;
            $fields[sprintf('type_%d', $field->type)] = $value;
            $offset += $field->length;
        }

        return $fields;
    }

    /**
     * @return int|string|null
     */
    private function decodeField(NetFlowField $field, string $bytes): mixed
    {
        if (($field->type === NetFlowField::IPV4_SRC_ADDR || $field->type === NetFlowField::IPV4_DST_ADDR) && $field->length === 4) {
            $address = inet_ntop($bytes);

            return $address === false ? null : $address;
        }

        return $this->decodeUnsignedInteger($bytes);
    }

    private function decodeUnsignedInteger(string $bytes): ?int
    {
        return match (strlen($bytes)) {
            1 => unpack('Cvalue', $bytes)['value'],
            2 => unpack('nvalue', $bytes)['value'],
            4 => unpack('Nvalue', $bytes)['value'],
            8 => $this->decodeUnsignedInteger64($bytes),
            default => null,
        };
    }

    private function decodeUnsignedInteger64(string $bytes): ?int
    {
        if (PHP_INT_SIZE < 8) {
            return null;
        }

        $parts = unpack('Nhigh/Nlow', $bytes);
        if ($parts === false) {
            return null;
        }

        if ($parts['high'] > intdiv(PHP_INT_MAX - $parts['low'], 4294967296)) {
            return null;
        }

        return ($parts['high'] * 4294967296) + $parts['low'];
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, int>   $header
     */
    private function buildParsedFlow(string $exporterIp, int $sourceId, array $fields, array $header): ParsedFlow
    {
        return new ParsedFlow(
            exporterIp: $exporterIp,
            sourceId: $sourceId,
            srcIPv4: $fields['srcIPv4'] ?? null,
            dstIPv4: $fields['dstIPv4'] ?? null,
            bytes: $fields['bytes'] ?? null,
            packets: $fields['packets'] ?? null,
            protocol: $fields['protocol'] ?? null,
            srcPort: $fields['srcPort'] ?? null,
            dstPort: $fields['dstPort'] ?? null,
            inputInterface: $fields['inputInterface'] ?? null,
            outputInterface: $fields['outputInterface'] ?? null,
            tcpFlags: $fields['tcpFlags'] ?? null,
            firstSeen: $this->convertSwitchedTime($fields['firstSeen'] ?? null, $header['sysUptime'], $header['unixSecs']),
            lastSeen: $this->convertSwitchedTime($fields['lastSeen'] ?? null, $header['sysUptime'], $header['unixSecs']),
            rawFields: $fields,
        );
    }

    private function convertSwitchedTime(mixed $switchedValue, int $sysUptime, int $unixSecs): ?DateTimeImmutable
    {
        if (!is_int($switchedValue) || $switchedValue < 0 || $sysUptime < $switchedValue) {
            return null;
        }

        $timestamp = $unixSecs - (($sysUptime - $switchedValue) / 1000);
        if ($timestamp <= 0) {
            return null;
        }

        return DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $timestamp)) ?: null;
    }
}
