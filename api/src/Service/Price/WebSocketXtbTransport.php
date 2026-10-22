<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Price;

use MyPortfolio\Service\Market\MarketDataException;
use WebSocket\Client;
use WebSocket\Message\Text;

/**
 * Přihlášení `logonRestricted` veřejnými údaji widgetu a `getChartHistoryByDate`
 * (port z crypto-portfolio, ExchangeRate::getXtbSocketPrice).
 */
final class WebSocketXtbTransport implements XtbWidgetTransport
{
    private const TIMEOUT_SECONDS = 10;
    private const MAX_MESSAGES = 50;

    public function chart(array $config, int $assetClass, string $symbol, int $quoteId, int $startMs, int $endMs, int $intervalMs): array
    {
        try {
            $client = new Client($config['url']);
            $client->setTimeout(self::TIMEOUT_SECONDS);
            try {
                $this->request($client, 'logonRestricted', ['CoreAPI' => [
                    'endpoint'        => $config['endpoint'],
                    'logonRestricted' => ['user' => $config['user'], 'accessCode' => $config['accessCode']],
                ]]);
                $response = $this->request($client, 'chart', ['IXServerAPI' => [
                    'endpoint'              => $config['endpoint'],
                    'accountId'             => $config['accountId'],
                    'getChartHistoryByDate' => [
                        'assetClass' => $assetClass,
                        'symbol'     => $symbol,
                        'quoteId'    => $quoteId,
                        'start'      => $startMs,
                        'end'        => $endMs,
                        'interval'   => $intervalMs,
                    ],
                ]]);
            } finally {
                $client->close();
            }
        } catch (MarketDataException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new MarketDataException('XTB WebSocket: ' . $e->getMessage(), 0, $e);
        }

        $bars = [];
        foreach ($response['response'][0]['xcharthistory']['xRateInfos'] ?? [] as $bar) {
            if (isset($bar['time'], $bar['close']) && is_numeric($bar['close'])) {
                $bars[] = ['time' => (int) $bar['time'], 'close' => $bar['close']];
            }
        }
        return $bars;
    }

    /**
     * @param array<string,mixed> $command
     * @return array<string,mixed>
     */
    private function request(Client $client, string $reqId, array $command): array
    {
        $client->send(new Text(json_encode(['reqId' => $reqId, 'command' => $command], JSON_THROW_ON_ERROR)));
        for ($i = 0; $i < self::MAX_MESSAGES; $i++) {
            $data = json_decode($client->receive()->getContent(), true);
            if (!is_array($data) || ($data['reqId'] ?? null) !== $reqId) {
                continue;
            }
            if (($data['status'] ?? 0) !== 0) {
                throw new MarketDataException("XTB {$reqId}: " . ($data['errorDescr'] ?? 'status ' . $data['status']));
            }
            return $data;
        }
        throw new MarketDataException("XTB {$reqId}: odpověď nepřišla.");
    }
}
