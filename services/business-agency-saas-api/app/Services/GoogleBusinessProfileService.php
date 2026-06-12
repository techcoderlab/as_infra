<?php

namespace App\Services;

use App\Models\GoogleAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleBusinessProfileService
{
    protected string $clientId;

    protected string $clientSecret;

    public function __construct()
    {
        $this->clientId = config('services.google_business.client_id');
        $this->clientSecret = config('services.google_business.client_secret');
    }

    public function getAuthUrl(string $state): string
    {
        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => route('google-business.callback'),
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/business.manage',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ];

        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query($params);
    }

    public function exchangeCodeForTokens(string $code): array
    {
        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'redirect_uri' => route('google-business.callback'),
            'grant_type' => 'authorization_code',
        ]);

        if ($response->failed()) {
            Log::error('[GoogleBusinessProfileService] Failed to exchange code', [
                'response' => $response->body(),
            ]);
            throw new \Exception('Failed to exchange Google code.');
        }

        return $response->json();
    }

    /**
     * Fetch the user's accounts/locations to discover the location name.
     */
    public function discoverLocation(string $token): ?string
    {
        // First get the account
        $response = Http::withToken($token)
            ->get('https://mybusinessaccountmanagement.googleapis.com/v1/accounts');

        if ($response->failed()) {
            return null;
        }

        $accounts = $response->json()['accounts'] ?? [];
        if (empty($accounts)) {
            return null;
        }

        $accountName = $accounts[0]['name'];

        // Then get locations for that account
        $response = Http::withToken($token)
            ->get("https://mybusinessbusinessinformation.googleapis.com/v1/{$accountName}/locations", [
                'readMask' => 'name,title',
            ]);

        if ($response->failed()) {
            return null;
        }

        $locations = $response->json()['locations'] ?? [];
        if (empty($locations)) {
            return null;
        }

        return $locations[0]['name'];
    }

    /**
     * Ensure the access token is valid, refreshing it if necessary.
     */
    public function ensureValidToken(GoogleAccount $account): string
    {
        if ($account->expires_at && $account->expires_at->isFuture()) {
            return $account->access_token;
        }

        return $this->refreshToken($account);
    }

    /**
     * Refresh the Google OAuth access token.
     */
    protected function refreshToken(GoogleAccount $account): string
    {
        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $account->refresh_token,
            'grant_type' => 'refresh_token',
        ]);

        if ($response->failed()) {
            Log::error('[GoogleBusinessProfileService] Failed to refresh token', [
                'tenant_id' => $account->tenant_id,
                'email' => $account->email,
                'response' => $response->body(),
            ]);
            throw new \Exception('Failed to refresh Google token.');
        }

        $data = $response->json();
        $account->update([
            'access_token' => $data['access_token'],
            'expires_at' => now()->addSeconds($data['expires_in']),
        ]);

        return $data['access_token'];
    }

    /**
     * Fetch reviews for the given business account.
     * Note: This assumes we have already discovered the location name.
     */
    public function fetchReviews(GoogleAccount $account, string $locationName): array
    {
        $token = $this->ensureValidToken($account);

        $response = Http::withToken($token)
            ->get("https://mybusinessreviews.googleapis.com/v1/{$locationName}/reviews");

        if ($response->failed()) {
            Log::error('[GoogleBusinessProfileService] Failed to fetch reviews', [
                'location' => $locationName,
                'response' => $response->body(),
            ]);

            return [];
        }

        return $response->json()['reviews'] ?? [];
    }

    /**
     * Post a reply to a review.
     */
    public function postReply(GoogleAccount $account, string $reviewName, string $reply): bool
    {
        $token = $this->ensureValidToken($account);

        $response = Http::withToken($token)
            ->patch("https://mybusinessreviews.googleapis.com/v1/{$reviewName}/reply", [
                'comment' => $reply,
            ]);

        if ($response->failed()) {
            Log::error('[GoogleBusinessProfileService] Failed to post reply', [
                'review' => $reviewName,
                'response' => $response->body(),
            ]);

            return false;
        }

        return true;
    }
}
