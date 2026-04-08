<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework. You can also check out [Laravel Learn](https://laravel.com/learn), where you will be guided through building a modern Laravel application.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Redberry](https://redberry.international/laravel-development)**
- **[Active Logic](https://activelogic.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

## DeyMake OAuth setup

This backend handles Google and Facebook OAuth for the React frontend.

### Required backend env values

- `APP_URL=https://api.deymake.com`
- `FRONTEND_URL=https://deymake.com`
- `GOOGLE_CLIENT_ID=...`
- `GOOGLE_CLIENT_SECRET=...`
- `GOOGLE_REDIRECT_URI=https://api.deymake.com/api/v1/auth/oauth/google/callback`
- `FACEBOOK_CLIENT_ID=...`
- `FACEBOOK_CLIENT_SECRET=...`
- `FACEBOOK_REDIRECT_URI=https://api.deymake.com/api/v1/auth/oauth/facebook/callback`

### Google console settings

- OAuth client type: `Web application`
- Authorized redirect URI:
  - `https://api.deymake.com/api/v1/auth/oauth/google/callback`
- Authorized JavaScript origins are not required for this server-side flow unless you later add a browser SDK.

### Facebook console settings

- Valid OAuth Redirect URI:
  - `https://api.deymake.com/api/v1/auth/oauth/facebook/callback`
- App domain:
  - `deymake.com`
- If Facebook requires a site URL, use:
  - `https://deymake.com`

### Runtime flow

1. Frontend sends users to `/api/v1/auth/oauth/{provider}/redirect`
2. Backend redirects to Google or Facebook
3. Provider returns to the backend callback URL
4. Backend creates a Sanctum token and redirects to `https://deymake.com/auth/callback`
5. Frontend stores the token and loads `/auth/me`

### After changing env values

- Run `php artisan migrate`
- Run `php artisan config:clear`

## Realtime setup (Laravel Reverb)

This API now supports realtime broadcasting for:

- conversation messages
- conversation presence and typing channel auth
- live-room stage signals
- live-room engagements
- live-room audience and presence updates

### Required backend env values

- `BROADCAST_CONNECTION=reverb`
- `REVERB_APP_ID=...`
- `REVERB_APP_KEY=...`
- `REVERB_APP_SECRET=...`
- `REVERB_HOST=127.0.0.1`
- `REVERB_PORT=8080`
- `REVERB_SCHEME=http`

### Local startup

Run these in separate terminals:

- `php artisan serve`
- `php artisan reverb:start`

After changing broadcasting or Reverb env values, also run:

- `php artisan optimize:clear`

"# deymake_backend" 
