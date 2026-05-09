import http from 'k6/http';
import { check, sleep } from 'k6';

export const options = {
  scenarios: {
    ramp_load: {
      executor: 'ramping-vus',
      startVUs: 10,
      stages: [
        { duration: '2m', target: 50 },
        { duration: '3m', target: 120 },
        { duration: '2m', target: 50 },
        { duration: '1m', target: 0 },
      ],
      gracefulRampDown: '30s',
    },
  },
  thresholds: {
    http_req_duration: ['p(95)<1500', 'p(99)<2500'],
    http_req_failed: ['rate<0.03'],
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
  const endpoints = [
    `${BASE_URL}/api/v1/calls?per_page=25`,
    `${BASE_URL}/api/v1/inbox/threads?channel=sms&per_page=25`,
    `${BASE_URL}/api/v1/reporting/unified?tenant_id=${TENANT_ID}`,
  ];

  for (const url of endpoints) {
    const response = http.get(url, { headers: authHeaders() });
    check(response, {
      'status is 200': (r) => r.status === 200,
    });
  }

  sleep(0.5);
}
