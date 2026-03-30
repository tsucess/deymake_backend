<?php

namespace Tests\Feature;

use Tests\TestCase;

class InfoApiTest extends TestCase
{
    public function test_info_endpoints_honor_locale_headers(): void
    {
        $this->withHeaders(['X-Locale' => 'es'])
            ->getJson('/api/v1/help')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.info.help_retrieved', [], 'es'))
            ->assertJsonPath('data.title', trans('messages.info.help_title', [], 'es'));

        $this->withHeaders(['X-Locale' => 'fr'])
            ->getJson('/api/v1/legal/privacy')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.info.privacy_retrieved', [], 'fr'))
            ->assertJsonPath('data.title', trans('messages.info.privacy_title', [], 'fr'));

        $this->withHeaders(['X-Locale' => 'yo'])
            ->getJson('/api/v1/legal/terms')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.info.terms_retrieved', [], 'yo'))
            ->assertJsonPath('data.title', trans('messages.info.terms_title', [], 'yo'));
    }
}