<?php

namespace Tests\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;

class Settings
{
    private Setting $setting;

    private function __construct()
    {
        $this->setting = Setting::factory()->create();
    }

    public static function initialize(): Settings
    {
        return new self();
    }

    public function enableAlertEmail(string $email = 'notifications@afcrichmond.com'): Settings
    {
        return $this->update(['alert_email' => $email]);
    }

    public function disableAlertEmail(): Settings
    {
        return $this->update(['alert_email' => null]);
    }

    public function enableMultipleFullCompanySupport(): Settings
    {
        return $this->update(['full_multiple_companies_support' => 1]);
    }

    public function disableMultipleFullCompanySupport(): Settings
    {
        return $this->update(['full_multiple_companies_support' => 0]);
    }

    public function enableWebhook(): Settings
    {
        return $this->update([
            'webhook_botname' => 'SnipeBot5000',
            'webhook_endpoint' => 'https://hooks.slack.com/services/NZ59/Q446/672N',
            'webhook_channel' => '#it',
        ]);
    }

    public function disableWebhook(): Settings
    {
        return $this->update([
            'webhook_botname' => '',
            'webhook_endpoint' => '',
            'webhook_channel' => '',
        ]);
    }

    public function enableAutoIncrement(): Settings
    {
        return $this->update([
            'auto_increment_assets' => 1,
            'auto_increment_prefix' => 'ABCD',
            'next_auto_tag_base' => '123',
            'zerofill_count' => 5
        ]);

    }

    public function enableLdap(): Settings
    {
        return $this->update([
            'ldap_enabled' => 1,
            'ldap_server' => 'ldaps://ldap.example.com',
            'ldap_uname' => 'fake_username',
            'ldap_pword' => Crypt::encrypt("fake_password"),
            'ldap_basedn' => 'CN=Users,DC=ad,DC=example,Dc=com'
        ]);
    }

    public function enableAnonymousLdap(): Settings
    {
        return $this->update([
            'ldap_enabled' => 1,
            'ldap_server' => 'ldaps://ldap.example.com',
//            'ldap_uname' => 'fake_username',
            'ldap_pword' => Crypt::encrypt("fake_password"),
            'ldap_basedn' => 'CN=Users,DC=ad,DC=example,Dc=com'
        ]);
    }

    public function enableBadPasswordLdap(): Settings
    {
        return $this->update([
            'ldap_enabled' => 1,
            'ldap_server' => 'ldaps://ldap.example.com',
            'ldap_uname' => 'fake_username',
            'ldap_pword' => "badly_encrypted_password!",
            'ldap_basedn' => 'CN=Users,DC=ad,DC=example,Dc=com'
        ]);
    }

    /**
     * @param array $attributes Attributes to modify in the application's settings.
     */
    public function set(array $attributes): Settings
    {
        return $this->update($attributes);
    }

    private function update(array $attributes): Settings
    {
        Setting::unguarded(fn() => $this->setting->update($attributes));
        Setting::$_cache = null;

        return $this;
    }
}
