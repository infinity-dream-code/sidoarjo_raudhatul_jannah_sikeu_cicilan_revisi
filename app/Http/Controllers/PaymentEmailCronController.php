<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\HttpFoundation\Response;

class PaymentEmailCronController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $expected = (string) config('email_broadcaster.payment_cron_token', '');
        $given = (string) $request->query('token', '');

        if ($expected === '' || !hash_equals($expected, $given)) {
            return response('Forbidden', 403)->header('Content-Type', 'text/plain');
        }

        $exit = Artisan::call('payment-email:dispatch');
        $output = trim(Artisan::output());

        return response($output !== '' ? $output : 'ok', $exit === 0 ? 200 : 500)
            ->header('Content-Type', 'text/plain');
    }
}
