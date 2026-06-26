<?php

namespace Numok\Controllers;

use Numok\Support\Brand;

class PartnerBaseController extends Controller {
    /**
     * Brand/host program scope.
     *
     * When the current host is scoped to a single brand program
     * (Brand::programRef() not null), returns a SQL fragment + params that
     * restrict a query to that program. On the unscoped host (e.g. 9forlives)
     * it returns empty strings — a no-op that keeps the global behaviour.
     *
     * Uses a subquery on programs.external_ref so it works for any query
     * regardless of which tables it joins, as long as it exposes the
     * program id column ($programIdExpr, default the partner_programs alias).
     *
     * @return array{sql:string, params:array}
     */
    protected function brandProgramFilter(string $programIdExpr = 'pp.program_id'): array {
        $ref = Brand::programRef();
        if ($ref === null) {
            return ['sql' => '', 'params' => []];
        }
        return [
            'sql'    => " AND {$programIdExpr} IN (SELECT id FROM programs WHERE external_ref = ?)",
            'params' => [$ref],
        ];
    }

    /**
     * Same brand scope as brandProgramFilter(), but as a bare condition (no
     * leading " AND ") for queries that build a $whereConditions[] array.
     *
     * @return array{cond:?string, params:array}
     */
    protected function brandProgramCondition(string $programIdExpr = 'pp.program_id'): array {
        $ref = Brand::programRef();
        if ($ref === null) {
            return ['cond' => null, 'params' => []];
        }
        return [
            'cond'   => "{$programIdExpr} IN (SELECT id FROM programs WHERE external_ref = ?)",
            'params' => [$ref],
        ];
    }

    protected function view(string $template, array $data = []): void {
        // Always include settings in view data
        if (!isset($data['settings'])) {
            $data['settings'] = $this->getSettings();
        }
        
        extract($data);
        
        require ROOT_PATH . "/src/Views/partner/layouts/header.php";
        require ROOT_PATH . "/src/Views/{$template}.php";
        require ROOT_PATH . "/src/Views/partner/layouts/footer.php";
    }
}