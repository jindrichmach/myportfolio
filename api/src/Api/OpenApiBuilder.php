<?php

declare(strict_types=1);

namespace MyPortfolio\Api;

/**
 * OpenAPI 3.1 z ApiCatalog (Swagger UI, Postman, generování klientů). Texty v jazyce `$locale`.
 */
final class OpenApiBuilder
{
    private const TYPES = [
        'integer'  => ['type' => 'integer'],
        'boolean'  => ['type' => 'boolean'],
        'string'   => ['type' => 'string'],
        'date'     => ['type' => 'string', 'format' => 'date'],
        'datetime' => ['type' => 'string', 'examples' => ['2026-01-15 10:30']],
        'decimal'  => ['type' => 'string', 'pattern' => '^-?\d+([.,]\d+)?$'],
        'currency' => ['type' => 'string', 'pattern' => '^[A-Z]{3}$'],
        'object'   => ['type' => 'object'],
        'file'     => ['type' => 'string', 'format' => 'binary'],
    ];

    /** @return array<string,mixed> */
    public static function build(string $serverUrl, string $version, string $locale): array
    {
        $en = $locale === 'en';
        $paths = [];
        foreach (ApiCatalog::localized($locale) as $e) {
            $parameters = [];
            $body = ['properties' => [], 'required' => []];
            foreach ($e['params'] as $p) {
                $schema = self::schema($p);
                if (in_array($p['in'], ['path', 'query'], true)) {
                    $parameters[] = array_filter(['name' => $p['name'], 'in' => $p['in'], 'required' => $p['in'] === 'path' || $p['required'],
                        'description' => $p['description'], 'schema' => $schema], static fn ($v) => $v !== null);
                    continue;
                }
                $body['properties'][$p['name']] = $schema + ['description' => $p['description']];
                if ($p['required']) {
                    $body['required'][] = $p['name'];
                }
            }
            $operation = [
                'operationId' => $e['id'],
                'tags'        => [$e['group_label']],
                'summary'     => $e['summary'],
                'description' => ($en ? 'Token scope: ' : 'Rozsah tokenu: ') . '`' . $e['scope'] . '`',
            ];
            if ($parameters !== []) {
                $operation['parameters'] = $parameters;
            }
            if ($body['properties'] !== []) {
                $mime = $e['content_type'] === 'multipart/form-data' ? 'multipart/form-data' : 'application/json';
                $operation['requestBody'] = ['required' => $body['required'] !== [], 'content' => [$mime => ['schema' => array_filter(
                    ['type' => 'object', 'properties' => $body['properties'], 'required' => $body['required'] ?: null], static fn ($v) => $v !== null)]]];
            }
            $response = ['description' => $e['response']['description']];
            if ($e['response']['status'] !== 204) {
                $mime = $e['content_type'] === 'text/csv' ? 'text/csv' : 'application/json';
                $response['content'] = [$mime => $e['response']['example'] !== null ? ['example' => $e['response']['example']] : (object) []];
            }
            $operation['responses'] = [
                (string) $e['response']['status'] => $response,
                'default' => ['$ref' => '#/components/responses/Error'],
            ];
            $paths[$e['path']][strtolower($e['method'])] = $operation;
        }

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'MyPortfolio API',
                'version' => $version,
                'description' => $en
                    ? 'Personal investment tracker API. Authenticate with `Authorization: Bearer <token>` (Profile → API). Amounts, prices and quantities are decimal strings, never floats.'
                    : 'API správy osobních investic. Ověření hlavičkou `Authorization: Bearer <token>` (Profil → API). Částky, ceny a množství jsou desetinné řetězce, nikdy float.',
            ],
            'servers' => [['url' => rtrim($serverUrl, '/')]],
            'security' => [['bearerAuth' => []]],
            'tags' => array_values(array_map(static fn (array $g) => ['name' => $g[$en ? 1 : 0]], ApiCatalog::groups())),
            'paths' => $paths,
            'components' => [
                'securitySchemes' => ['bearerAuth' => ['type' => 'http', 'scheme' => 'bearer']],
                'responses' => ['Error' => [
                    'description' => $en ? 'Error (400 validation, 401, 403 scope, 404, 409, 422, 429 rate limit)' : 'Chyba (400 validace, 401, 403 rozsah, 404, 409, 422, 429 limit)',
                    'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['error' => ['type' => 'object', 'properties' => [
                        'code' => ['type' => 'string'], 'message' => ['type' => 'string'], 'fields' => ['type' => 'object'],
                    ]]]]]],
                ]],
            ],
        ];
    }

    /** @param array<string,mixed> $p @return array<string,mixed> */
    private static function schema(array $p): array
    {
        $schema = self::TYPES[$p['type']] ?? ['type' => 'string'];
        foreach (['enum', 'default'] as $k) {
            if ($p[$k] !== null) {
                $schema[$k] = $p[$k];
            }
        }
        if ($p['example'] !== null) {
            $schema['examples'] = [$p['example']];
        }
        return $schema;
    }
}
