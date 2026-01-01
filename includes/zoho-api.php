<?php
if (!defined('ABSPATH')) exit;

function zoho_get_access_token() {
    return get_option('zoho_access_token');
}

function zoho_get_organization_id() {
    return get_option('zoho_organization_id');
}

function zoho_refresh_access_token() {
    $refresh_token = get_option('zoho_refresh_token');
    $client_id = get_option('zoho_client_id');
    $client_secret = get_option('zoho_client_secret');
    
    error_log('Token Refresh Attempt - Refresh token exists: ' . (!empty($refresh_token) ? 'YES' : 'NO'));
    
    if (empty($refresh_token) || empty($client_id) || empty($client_secret)) {
        error_log('Token Refresh FAILED: Missing credentials');
        return false;
    }
    
    // Try multiple Zoho domains (different regions use different domains)
    $zoho_domains = array(
        'https://accounts.zoho.com/oauth/v2/token',
        'https://accounts.zoho.in/oauth/v2/token',
        'https://accounts.zoho.eu/oauth/v2/token',
        'https://accounts.zoho.com.au/oauth/v2/token',
    );
    
    foreach ($zoho_domains as $token_url) {
        $response = wp_remote_post($token_url, [
            'body' => [
                'grant_type' => 'refresh_token',
                'client_id' => $client_id,
                'client_secret' => $client_secret,
                'refresh_token' => $refresh_token
            ],
            'timeout' => 20
        ]);
        
        if (is_wp_error($response)) {
            continue;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['access_token'])) {
            update_option('zoho_access_token', $data['access_token']);
            // Always set expiry time to 1 hour (3600 seconds) from now
            update_option('zoho_token_expires_at', time() + 3600);
            error_log('Token Refresh SUCCESS - New token saved');
            return true;
        } else {
            error_log('Token Refresh FAILED from ' . $token_url . ' - Response: ' . json_encode($data));
        }
    }
    
    error_log('Token Refresh FAILED - Tried all domains');
    return false;
}

function zoho_is_auth_error($data, $http_status_code = null) {
    // Check HTTP status code 401 (Unauthorized)
    if ($http_status_code == 401) {
        return true;
    }
    
    // Check for various authorization error indicators in response body
    if (isset($data['code']) && $data['code'] == 57) return true;
    if (isset($data['code']) && $data['code'] == 6041) return true; // Invalid token
    if (isset($data['code']) && $data['code'] == 6051) return true; // Token expired
    
    // Check message for auth-related errors
    $message = isset($data['message']) ? strtolower($data['message']) : '';
    if (strpos($message, 'not authorized') !== false) return true;
    if (strpos($message, 'invalid oauth token') !== false) return true;
    if (strpos($message, 'token expired') !== false) return true;
    if (strpos($message, 'oauth token is invalid') !== false) return true;
    if (strpos($message, 'unauthorized') !== false) return true;
    
    return false;
}

function zoho_api_get($endpoint, $retry_on_auth_error = true) {
    $token = zoho_get_access_token();
    $org_id = zoho_get_organization_id();
    
    if (empty($token)) {
        return ['error' => 'No access token found. Please connect to Zoho first.', 'code' => 57];
    }
    
    // Add organization_id if not in endpoint
    $url = 'https://www.zohoapis.com/inventory/v1/' . $endpoint;
    if (!empty($org_id) && strpos($endpoint, 'organization_id') === false) {
        $url .= (strpos($endpoint, '?') !== false ? '&' : '?') . 'organization_id=' . $org_id;
    }

    $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => 'Zoho-oauthtoken ' . $token,
            ],
        'timeout' => 30
    ]);
    
    // Check for WP_Error
    if (is_wp_error($response)) {
        return $response;
    }
    
    // Get HTTP status code
    $http_code = wp_remote_retrieve_response_code($response);
    
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    
    // Check if JSON decode failed
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['error' => 'Failed to parse API response: ' . json_last_error_msg()];
    }
    
    // Check for 6024 error - multiple organizations, need to select one
    if (isset($data['code']) && $data['code'] == 6024) {
        return $data;
    }
    
    // If authorization error (401 or auth-related) and retry allowed, refresh token and retry
    if (zoho_is_auth_error($data, $http_code) && $retry_on_auth_error) {
        if (zoho_refresh_access_token()) {
            // Retry the same API call with new token
            return zoho_api_get($endpoint, false);
        }
    }
    
    return $data;
}

function zoho_api_post($endpoint, $data, $retry_on_auth_error = true) {
    $token = zoho_get_access_token();
    $org_id = zoho_get_organization_id();
    
    if (empty($token)) {
        return ['error' => 'No access token found. Please connect to Zoho first.', 'code' => 57];
    }
    
    $url = 'https://www.zohoapis.com/inventory/v1/' . $endpoint;
    if (!empty($org_id) && strpos($endpoint, 'organization_id') === false) {
        $url .= (strpos($endpoint, '?') !== false ? '&' : '?') . 'organization_id=' . $org_id;
    }

    $response = wp_remote_post($url, [
        'headers' => [
            'Authorization' => 'Zoho-oauthtoken ' . $token,
            'Content-Type' => 'application/json'
        ],
        'body' => json_encode($data),
        'timeout' => 30
    ]);
    
    if (is_wp_error($response)) {
        return $response;
    }
    
    // Get HTTP status code
    $http_code = wp_remote_retrieve_response_code($response);
    
    $body = wp_remote_retrieve_body($response);
    $result = json_decode($body, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['error' => 'Failed to parse API response: ' . json_last_error_msg()];
    }
    
    // Check for 6024 error - multiple organizations, need to select one
    if (isset($result['code']) && $result['code'] == 6024) {
        return $result;
    }
    
    // If authorization error (401 or auth-related) and retry allowed, refresh token and retry
    if (zoho_is_auth_error($result, $http_code) && $retry_on_auth_error) {
        if (zoho_refresh_access_token()) {
            // Retry the same API call with new token
            return zoho_api_post($endpoint, $data, false);
        }
    }
    
    return $result;
}

function zoho_api_put($endpoint, $data, $retry_on_auth_error = true) {
    $token = zoho_get_access_token();
    $org_id = zoho_get_organization_id();
    
    if (empty($token)) {
        return ['error' => 'No access token found. Please connect to Zoho first.', 'code' => 57];
    }
    
    $url = 'https://www.zohoapis.com/inventory/v1/' . $endpoint;
    if (!empty($org_id) && strpos($endpoint, 'organization_id') === false) {
        $url .= (strpos($endpoint, '?') !== false ? '&' : '?') . 'organization_id=' . $org_id;
    }

    $response = wp_remote_request($url, [
        'method' => 'PUT',
        'headers' => [
            'Authorization' => 'Zoho-oauthtoken ' . $token,
            'Content-Type' => 'application/json'
        ],
        'body' => json_encode($data),
        'timeout' => 30
    ]);
    
    if (is_wp_error($response)) {
        return $response;
    }
    
    // Get HTTP status code
    $http_code = wp_remote_retrieve_response_code($response);
    
    $body = wp_remote_retrieve_body($response);
    $result = json_decode($body, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['error' => 'Failed to parse API response: ' . json_last_error_msg()];
    }
    
    // Check for 6024 error - multiple organizations, need to select one
    if (isset($result['code']) && $result['code'] == 6024) {
        return $result;
    }
    
    // If authorization error (401 or auth-related) and retry allowed, refresh token and retry
    if (zoho_is_auth_error($result, $http_code) && $retry_on_auth_error) {
        error_log('PUT API - Auth error detected. Code: ' . (isset($result['code']) ? $result['code'] : 'N/A') . ', HTTP: ' . $http_code);
        if (zoho_refresh_access_token()) {
            error_log('PUT API - Token refreshed successfully, retrying API call');
            // Retry the same API call with new token
            return zoho_api_put($endpoint, $data, false);
        } else {
            error_log('PUT API - Token refresh FAILED');
        }
    }
    
    return $result;
}

function zoho_api_delete($endpoint, $retry_on_auth_error = true) {
    $token = zoho_get_access_token();
    $org_id = zoho_get_organization_id();
    
    if (empty($token)) {
        return ['error' => 'No access token found. Please connect to Zoho first.', 'code' => 57];
    }
    
    $url = 'https://www.zohoapis.com/inventory/v1/' . $endpoint;
    if (!empty($org_id) && strpos($endpoint, 'organization_id') === false) {
        $url .= (strpos($endpoint, '?') !== false ? '&' : '?') . 'organization_id=' . $org_id;
    }

    $response = wp_remote_request($url, [
        'method' => 'DELETE',
        'headers' => [
            'Authorization' => 'Zoho-oauthtoken ' . $token,
            'Content-Type' => 'application/json'
        ],
        'timeout' => 30
    ]);
    
    if (is_wp_error($response)) {
        return $response;
    }
    
    // Get HTTP status code
    $http_code = wp_remote_retrieve_response_code($response);
    
    $body = wp_remote_retrieve_body($response);
    $result = json_decode($body, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['error' => 'Failed to parse API response: ' . json_last_error_msg()];
    }
    
    // Check for 6024 error - multiple organizations, need to select one
    if (isset($result['code']) && $result['code'] == 6024) {
        return $result;
    }
    
    // If authorization error (401 or auth-related) and retry allowed, refresh token and retry
    if (zoho_is_auth_error($result, $http_code) && $retry_on_auth_error) {
        if (zoho_refresh_access_token()) {
            // Retry the same API call with new token
            return zoho_api_delete($endpoint, false);
        }
    }
    
    return $result;
}

/**
 * Fetch list of organizations from Zoho Inventory
 * Returns array of organizations or error
 */
function zoho_fetch_organizations() {
    $token = zoho_get_access_token();
    
    if (empty($token)) {
        return ['error' => 'No access token found. Please connect to Zoho first.', 'code' => 57];
    }
    
    // Try different Zoho API domains
    $zoho_domains = array(
        'https://www.zohoapis.com/inventory/v1/organizations',
        'https://www.zohoapis.in/inventory/v1/organizations',
        'https://www.zohoapis.eu/inventory/v1/organizations',
        'https://www.zohoapis.com.au/inventory/v1/organizations',
    );
    
    foreach ($zoho_domains as $url) {
        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => 'Zoho-oauthtoken ' . $token,
            ],
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            continue;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        // If successful, return organizations
        if (isset($data['organizations']) && is_array($data['organizations'])) {
            return $data;
        }
        
        // If not an auth error, break and return error
        if (!zoho_is_auth_error($data)) {
            return $data;
        }
    }
    
    return ['error' => 'Failed to fetch organizations', 'code' => 0];
}
