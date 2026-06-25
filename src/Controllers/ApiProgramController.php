<?php

namespace Numok\Controllers;

use Numok\Database\Database;
use Numok\Services\ProgramScriptGenerator;

/**
 * Secure machine-to-machine API for provisioning affiliate programs.
 *
 * Used by the Supabase edge function `provision-numok-program` to ensure a
 * Numok program exists for a given store (one program per store). Idempotent:
 * keyed by programs.external_ref so repeated calls return the same program.
 *
 * Auth: Authorization: Bearer <token> compared against settings.api_token.
 */
class ApiProgramController extends Controller {

    public function createProgram(): void {
        $this->handlePreflightRequest();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            $this->json(['error' => 'Method not allowed']);
            return;
        }

        if (!$this->authorize()) {
            http_response_code(401);
            $this->json(['error' => 'Unauthorized']);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true) ?? [];

        $externalRef = trim((string)($input['external_ref'] ?? ''));
        if ($externalRef === '') {
            http_response_code(422);
            $this->json(['error' => 'external_ref is required']);
            return;
        }

        $name = trim((string)($input['name'] ?? '')) ?: ('Store ' . $externalRef);
        $description = trim((string)($input['description'] ?? '')) ?: ('Affiliate program for ' . $name);
        $commissionType = ($input['commission_type'] ?? 'percentage') === 'fixed' ? 'fixed' : 'percentage';
        $commissionValue = (float)($input['commission_value'] ?? 10);
        $cookieDays = (int)($input['cookie_days'] ?? 30);
        $rewardDays = (int)($input['reward_days'] ?? 0);
        $isRecurring = !empty($input['is_recurring']) ? 1 : 0;
        $landingPage = (string)($input['landing_page'] ?? '');

        try {
            // Idempotent UPSERT keyed by external_ref (one program per store).
            $existing = Database::query(
                "SELECT id FROM programs WHERE external_ref = ? LIMIT 1",
                [$externalRef]
            )->fetch();

            if ($existing) {
                $programId = (int)$existing['id'];
                $fields = [
                    'name' => $name,
                    'description' => $description,
                    'commission_type' => $commissionType,
                    'commission_value' => $commissionValue,
                    'cookie_days' => $cookieDays,
                    'is_recurring' => $isRecurring,
                    'reward_days' => $rewardDays,
                    'status' => 'active',
                ];
                // Only overwrite landing_page when a non-empty one is supplied,
                // so an auto-provision with no URL doesn't wipe an admin-set URL.
                if ($landingPage !== '') {
                    $fields['landing_page'] = $landingPage;
                }
                Database::update('programs', $fields, 'id = ?', [$programId]);

                $program = Database::query("SELECT * FROM programs WHERE id = ?", [$programId])->fetch();
                try {
                    ProgramScriptGenerator::generate($program, $_SERVER['HTTP_HOST'] ?? 'partners.9forlives.com');
                } catch (\Throwable $e) {
                    error_log('ApiProgramController: script generation failed: ' . $e->getMessage());
                }

                $this->json([
                    'program_id' => $programId,
                    'name' => $name,
                    'status' => 'active',
                    'created' => false,
                    'updated' => true,
                ]);
                return;
            }

            $programId = Database::transaction(function () use (
                $externalRef, $name, $description, $commissionType, $commissionValue,
                $cookieDays, $rewardDays, $isRecurring, $landingPage
            ) {
                $id = Database::insert('programs', [
                    'name' => $name,
                    'description' => $description,
                    'commission_type' => $commissionType,
                    'commission_value' => $commissionValue,
                    'cookie_days' => $cookieDays,
                    'is_recurring' => $isRecurring,
                    'reward_days' => $rewardDays,
                    'landing_page' => $landingPage,
                    'status' => 'active',
                    'external_ref' => $externalRef,
                ]);

                $program = Database::query(
                    "SELECT * FROM programs WHERE id = ?",
                    [$id]
                )->fetch();

                // Generate tracking script (best-effort; mirrors ProgramsController::store)
                try {
                    ProgramScriptGenerator::generate($program, $_SERVER['HTTP_HOST'] ?? 'partners.9forlives.com');
                } catch (\Throwable $e) {
                    error_log('ApiProgramController: script generation failed: ' . $e->getMessage());
                }

                return $id;
            });

            http_response_code(201);
            $this->json([
                'program_id' => (int)$programId,
                'name' => $name,
                'status' => 'active',
                'created' => true,
            ]);
        } catch (\Exception $e) {
            error_log('ApiProgramController::createProgram failed: ' . $e->getMessage());
            http_response_code(500);
            $this->json(['error' => 'Failed to create program']);
        }
    }

    /**
     * Resolve an affiliate tracking code to its partner (for showing
     * "Partner: <name>" at Stripe checkout). GET ?tracking_code=...
     */
    public function resolvePartner(): void {
        $this->handlePreflightRequest();

        if (!$this->authorize()) {
            http_response_code(401);
            $this->json(['error' => 'Unauthorized']);
            return;
        }

        $trackingCode = trim((string)($_GET['tracking_code'] ?? ''));
        if ($trackingCode === '') {
            http_response_code(422);
            $this->json(['error' => 'tracking_code is required']);
            return;
        }

        try {
            $row = Database::query(
                "SELECT pt.company_name, pt.contact_name, p.name AS program_name
                 FROM partner_programs pp
                 JOIN partners pt ON pp.partner_id = pt.id
                 JOIN programs  p  ON pp.program_id = p.id
                 WHERE pp.tracking_code = ? AND pp.status = 'active'
                 LIMIT 1",
                [$trackingCode]
            )->fetch();

            if (!$row) {
                $this->json(['found' => false]);
                return;
            }

            $this->json([
                'found' => true,
                'company_name' => $row['company_name'],
                'contact_name' => $row['contact_name'],
                'program_name' => $row['program_name'],
            ]);
        } catch (\Exception $e) {
            error_log('ApiProgramController::resolvePartner failed: ' . $e->getMessage());
            http_response_code(500);
            $this->json(['error' => 'Lookup failed']);
        }
    }

    private function authorize(): bool {
        $expected = $this->apiToken();
        if ($expected === '') {
            // No token configured → refuse rather than allow open access.
            error_log('ApiProgramController: api_token not configured');
            return false;
        }

        $provided = $this->bearerToken();
        if ($provided === '') {
            return false;
        }

        return hash_equals($expected, $provided);
    }

    private function apiToken(): string {
        $row = Database::query(
            "SELECT value FROM settings WHERE name = 'api_token' LIMIT 1"
        )->fetch();
        return trim((string)($row['value'] ?? ''));
    }

    private function bearerToken(): string {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if ($header === '' && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            $header = $headers['Authorization'] ?? ($headers['authorization'] ?? '');
        }
        if (preg_match('/Bearer\s+(.+)/i', $header, $m)) {
            return trim($m[1]);
        }
        return '';
    }
}
