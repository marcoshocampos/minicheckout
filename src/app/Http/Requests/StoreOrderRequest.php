<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Em centavos e inteiro: float em dinheiro é bug esperando acontecer.
            'amount_cents' => ['required', 'integer', 'min:1', 'max:100000000'],
            'currency' => ['required', 'string', 'size:3', 'in:BRL,USD'],
            'customer_email' => ['required', 'email:rfc'],
        ];
    }

    /**
     * Validação é erro ESPERADO: o 422 do Laravel responde ao cliente e não
     * precisa virar alerta nem ocupar ninguém de plantão. Na Fase 2 você vai
     * dizer isso explicitamente com `dontReport` em bootstrap/app.php.
     */
    public function messages(): array
    {
        return [
            'amount_cents.required' => 'Informe o valor em centavos.',
        ];
    }
}
