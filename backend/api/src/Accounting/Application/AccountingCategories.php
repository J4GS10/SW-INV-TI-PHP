<?php

declare(strict_types=1);

namespace App\Accounting\Application;

use App\Accounting\Domain\CategoryDraft;
use App\Accounting\Domain\DepreciationPolicy;
use App\Accounting\Infrastructure\AssetCategoryRepository;
use App\Db\Transaccion;
use App\Dominio\ErrorDominio;
use App\Repositorio\GobiernoRepositorio;

/**
 * The accounting categories of fixed assets: Guatemala's legal rates come preloaded and each country adds
 * its own. A category is never deleted, only deactivated, so the history keeps the rule it had.
 */
final readonly class AccountingCategories
{
    public function __construct(
        private AssetCategoryRepository $categories,
        private Transaccion $transaction,
        private GobiernoRepositorio $audit,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        return array_map(self::toApi(...), $this->categories->all());
    }

    /** @return array<string, mixed> */
    public function get(int $id): array
    {
        return self::toApi($this->required($id));
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function create(array $input, string $user, ?string $ip): array
    {
        $draft = CategoryDraft::fromInput($input, false)->normalized();
        $draft->policyOver([]);

        return $this->transaction->ejecutar(function () use ($draft, $user, $ip): array {
            $this->requireFreeCode((string) $draft->columns['FAC_COUNTRY'], (string) $draft->columns['FAC_CODE'], null);
            $id = $this->categories->insert($draft->columns);
            $this->audit->registrarAuditoria('CREATE', 'FIN_ASSET_CATEGORY', ['id' => $id, ...$draft->columns], $user, $ip);

            return $this->get($id);
        });
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function update(int $id, array $input, string $user, ?string $ip): array
    {
        $draft = CategoryDraft::fromInput($input, true)->normalized();

        return $this->transaction->ejecutar(function () use ($id, $draft, $user, $ip): array {
            if (!$this->categories->lock($id)) {
                throw ErrorDominio::noEncontrado('ACCOUNTING_CATEGORY_NOT_FOUND');
            }
            $current = $this->required($id);
            $draft->policyOver($current);
            if (isset($draft->columns['FAC_CODE']) || isset($draft->columns['FAC_COUNTRY'])) {
                $this->requireFreeCode((string) ($draft->columns['FAC_COUNTRY'] ?? $current['FAC_COUNTRY']), (string) ($draft->columns['FAC_CODE'] ?? $current['FAC_CODE']), $id);
            }
            $this->categories->update($id, $draft->columns);
            $this->audit->registrarAuditoria('UPDATE', 'FIN_ASSET_CATEGORY', ['id' => $id, 'cambios' => (object) $draft->columns], $user, $ip);

            return $this->get($id);
        });
    }

    /** An asset type can only point to an active category (null: the type has none). */
    public function requireActive(?int $id): void
    {
        if (null !== $id && !$this->categories->isActive($id)) {
            throw ErrorDominio::invalido('ACCOUNTING_CATEGORY_NOT_FOUND');
        }
    }

    /** @return array<string, mixed> */
    private function required(int $id): array
    {
        return $this->categories->find($id) ?? throw ErrorDominio::noEncontrado('ACCOUNTING_CATEGORY_NOT_FOUND');
    }

    private function requireFreeCode(string $country, string $code, ?int $except): void
    {
        if ($this->categories->codeTaken($country, $code, $except)) {
            throw ErrorDominio::conflicto('ACCOUNTING_CATEGORY_CODE_EXISTS');
        }
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return array<string, mixed>
     */
    private static function toApi(array $r): array
    {
        $policy = DepreciationPolicy::fromStored($r['FAC_METHOD'], $r['FAC_ANNUAL_RATE'], $r['FAC_RESIDUAL_PERCENT'], $r['FAC_STARTS_NEXT_MONTH']);

        return ['id' => (int) $r['FAC_ASSET_CATEGORY'], 'code' => $r['FAC_CODE'], 'name' => $r['FAC_NAME'], 'country' => $r['FAC_COUNTRY'],
            'method' => $r['FAC_METHOD'], 'annual_rate' => ValuationLines::number($r['FAC_ANNUAL_RATE']), 'residual_percent' => ValuationLines::number($r['FAC_RESIDUAL_PERCENT']),
            'starts_next_month' => 1 === (int) $r['FAC_STARTS_NEXT_MONTH'], 'useful_life_months' => $policy->usefulLifeMonths(),
            'account_code' => $r['FAC_ACCOUNT_CODE'], 'legal_basis' => $r['FAC_LEGAL_BASIS'], 'active' => 1 === (int) $r['FAC_ACTIVE'],
            'asset_types' => (int) $r['ASSET_TYPES'], 'row_version' => (int) $r['ROW_VERSION']];
    }
}
