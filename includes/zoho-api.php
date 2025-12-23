<?php
if (!defined('ABSPATH')) exit;

function zoho_get_access_token() {
    return get_option('zoho_access_token');
}

function zoho_refresh_access_token() {
    $refresh_token = get_option('zoho_refresh_token');
    $client_id = get_option('zoho_client_id');
    $client_secret = get_option('zoho_client_secret');
    
    if (empty($refresh_token) || empty($client_id) || empty($client_secret)) {
        return false;
    }
    
    $response = wp_remote_post('https://accounts.zoho.com/oauth/v2/token', [
        'body' => [
            'grant_type' => 'refresh_token',
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'refresh_token' => $refresh_token
        ],
        'timeout' => 20
    ]);
    
    if (is_wp_error($response)) {
        return false;
    }
    
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    
    if (isset($data['access_token'])) {
        update_option('zoho_access_token', $data['access_token']);
        if (isset($data['expires_in'])) {
            update_option('zoho_token_expires_at', time() + $data['expires_in']);
        }
        return true;
    }
    
    return false;
}

function zoho_api_get($endpoint, $retry_on_auth_error = true) {
    $token = zoho_get_access_token();
    
    if (empty($token)) {
        return ['error' => 'No access token found. Please connect to Zoho first.'];
    }

    $response = wp_remote_get(
        'https://www.zohoapis.com/inventory/v1/' . $endpoint,
        [
            'headers' => [
                'Authorization' => 'Zoho-oauthtoken ' . $token,
            ],
            'timeout' => 20
        ]
    );
    
    // Check for WP_Error
    if (is_wp_error($response)) {
        return $response;
    }
    
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    
    // Check if JSON decode failed
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['error' => 'Failed to parse API response: ' . json_last_error_msg()];
    }
    
    // Agar authorization error aaye (code 57) aur retry allowed hai, to token refresh karke phir try karo
    if (isset($data['code']) && $data['code'] == 57 && $retry_on_auth_error) {
        if (zoho_refresh_access_token()) {
            // Retry with new token
            return zoho_api_get($endpoint, false);
        }
    }
    
    return $data;
}
