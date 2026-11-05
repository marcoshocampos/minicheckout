// Smoke test: carga leve e saudavel, so para ver a stack respondendo.
//
//   docker compose run --rm k6 run /scripts/smoke.js
//
// Cada iteracao usa uma Idempotency-Key NOVA, ou seja: N requisicoes devem
// virar N pedidos. E o oposto do idempotency.js, que usa uma chave so.

import http from 'k6/http';
import { check } from 'k6';

export const options = {
  vus: 5,
  iterations: 50,
  thresholds: {
    http_req_failed: ['rate<0.01'],
    http_req_duration: ['p(95)<2000'],
  },
};

const BASE_URL = __ENV.BASE_URL || 'http://nginx';

// Sem jslib externa de proposito: o container do k6 nao precisa de internet.
function idemKey() {
  return `smoke-${__VU}-${__ITER}-${Date.now()}-${Math.floor(Math.random() * 1e9)}`;
}

export default function () {
  const res = http.post(
    `${BASE_URL}/orders`,
    JSON.stringify({ amount_cents: 1990, currency: 'BRL', customer_email: 'k6@example.test' }),
    {
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'Idempotency-Key': idemKey(),
        'X-Request-Id': `k6-${__VU}-${__ITER}`,
      },
    },
  );

  check(res, {
    'status 201': (r) => r.status === 201,
    'devolveu order id': (r) => {
      try {
        return !!r.json('id');
      } catch (e) {
        return false;
      }
    },
    'devolveu X-Request-Id': (r) => !!r.headers['X-Request-Id'],
  });
}
