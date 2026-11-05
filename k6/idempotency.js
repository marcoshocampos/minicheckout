// O teste que fecha a FASE 3: 20 requisicoes simultaneas com a MESMA
// Idempotency-Key devem gerar exatamente 1 pedido e 1 cobranca.
//
//   docker compose exec gateway curl -sS -XPOST localhost:8080/_reset
//   docker compose run --rm k6 run /scripts/idempotency.js
//   docker compose exec gateway curl -sS localhost:8080/_stats
//
// NA FASE 0 ELE TEM QUE FALHAR, e esse e o ponto: voce vai ver 20 pedidos no
// banco e 20 cobrancas no /_stats do gateway. Guarde esse numero: e a "foto
// do antes" que da sentido a Fase 3.
//
// Por que 20 ao mesmo tempo, e nao 20 em sequencia: em sequencia, um simples
// `if (ja existe) return` resolve. Simultaneas, todas leem "nao existe" antes
// de qualquer uma escrever. E por isso que idempotencia precisa de constraint
// no banco (ou lock), nao de `if`.

import http from 'k6/http';
import { check } from 'k6';

export const options = {
  scenarios: {
    // start-rate alto numa janela curta: as 20 saem praticamente juntas.
    rajada: {
      executor: 'shared-iterations',
      vus: 20,
      iterations: 20,
      maxDuration: '30s',
    },
  },
};

const BASE_URL = __ENV.BASE_URL || 'http://nginx';

// Uma chave para todas as VUs. Mude via `-e KEY=...` para repetir o teste limpo.
const KEY = __ENV.KEY || 'fase3-mesma-chave-001';

// Payload identico em todas: payload diferente com a mesma chave e o caso 422.
const PAYLOAD = JSON.stringify({
  amount_cents: 4990,
  currency: 'BRL',
  customer_email: 'corrida@example.test',
});

export default function () {
  const res = http.post(`${BASE_URL}/orders`, PAYLOAD, {
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'Idempotency-Key': KEY,
      'X-Request-Id': `k6-idem-${__VU}`,
    },
  });

  // 201 na primeira, 200 nos replays, 409 se ainda estava processando.
  // Nenhuma delas pode ser 500.
  check(res, {
    'nao deu 5xx': (r) => r.status < 500,
    'status esperado (200/201/409)': (r) => [200, 201, 409].includes(r.status),
  });

  console.log(`VU ${__VU}: HTTP ${res.status} -> ${String(res.body).slice(0, 120)}`);
}
