import http from 'k6/http';
import { check, sleep } from 'k6';

export const options = {
  stages: [
    { duration: '30s', target: 10 },
    { duration: '1m', target: 40 },
    { duration: '30s', target: 0 },
  ],
  thresholds: {
    http_req_duration: ['p(95)<1200'],
    http_req_failed: ['rate<0.02'],
  },
};

const BASE_URL = __ENV.BASE_URL || 'http://127.0.0.1:8000';
const API_TOKEN = __ENV.API_TOKEN || '';
const TENANT_ID = __ENV.TENANT_ID || '';

function authHeaders() {
  return {
    Authorization: `Bearer ${API_TOKEN}`,
    'X-Tenant-Id': TENANT_ID,
    Accept: 'application/json',
  };
}

export default function () {
  const calls = http.get(`${BASE_URL}/api/v1/calls?per_page=25`, {
    headers: authHeaders(),
  });
  check(calls, {
    'calls endpoint responds 200': (r) => r.status === 200,
  });

  const threads = http.get(`${BASE_URL}/api/v1/inbox/threads?channel=sms&per_page=25`, {
    headers: authHeaders(),
  });
  check(threads, {
    'threads endpoint responds 200': (r) => r.status === 200,
  });

  const reporting = http.get(`${BASE_URL}/api/v1/reporting/unified?tenant_id=${TENANT_ID}`, {
    headers: authHeaders(),
  });
  check(reporting, {
    'reporting endpoint responds 200': (r) => r.status === 200,
  });

  const gov = http.post(
    `${BASE_URL}/api/v1/governance/drill`,
    JSON.stringify({
      tenant_id: TENANT_ID,
      scenario: 'provider_outage',
    }),
    {
      headers: {
        ...authHeaders(),
        'Content-Type': 'application/json',
      },
    }
  );
  check(gov, {
    'governance drill responds 202': (r) => r.status === 202,
  });

  sleep(1);
}
