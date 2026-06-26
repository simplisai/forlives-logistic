<?php

namespace Numok\Controllers;

use Numok\Database\Database;
use Numok\Support\Brand;

class Controller {
    
    protected function handlePreflightRequest(): void {
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Access-Control-Max-Age: 86400');
            http_response_code(200);
            exit;
        }
    }

    protected function view(string $template, array $data = []): void {
        // Always include settings in view data
        if (!isset($data['settings'])) {
            $data['settings'] = $this->getSettings();
        }
        
        extract($data);
        
        require ROOT_PATH . "/src/Views/layouts/header.php";
        require ROOT_PATH . "/src/Views/{$template}.php";
        require ROOT_PATH . "/src/Views/layouts/footer.php";
    }

    protected function getSettings(): array {
        try {
            $stmt = Database::query("SELECT name, value FROM settings");
            $settings = [];
            
            while ($row = $stmt->fetch()) {
                $settings[$row['name']] = $row['value'];
            }

            // Branding is resolved per-host (single deployment, multiple branded
            // portals). The in-repo brand assets are the single source of truth:
            // always present, identical on every screen, and survive redeploys.
            // Ignore any uploaded custom_logo (ephemeral here).
            unset($settings['custom_logo']);

            $brand = Brand::current();
            $settings['custom_app_name']    = $brand['name'];
            $settings['brand_logo']         = $brand['logo'];
            $settings['brand_favicon']      = $brand['favicon'];
            $settings['brand_favicon_type'] = $brand['favicon_type'];

            return $settings;
        } catch (\Exception $e) {
            // Database unavailable: still resolve branding from the host so
            // login/error pages render with the correct brand.
            $brand = Brand::current();
            return [
                'custom_app_name'    => $brand['name'],
                'brand_logo'         => $brand['logo'],
                'brand_favicon'      => $brand['favicon'],
                'brand_favicon_type' => $brand['favicon_type'],
            ];
        }
    }

    protected function json(array $data): void {
        // Add CORS headers for all API responses
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Max-Age: 86400');
        
        header('Content-Type: application/json');
        echo json_encode($data);
    }
}