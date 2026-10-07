import { cookies } from 'next/headers';
import { NextRequest, NextResponse } from 'next/server';

const BACKEND_URL = process.env.BACKEND_INTERNAL_URL ?? 'http://localhost:8080';
const AUTH_COOKIE_NAME = process.env.AUTH_COOKIE_NAME ?? 'wsm_token';

async function proxy(request: NextRequest, segments: string[]): Promise<Response> {
  const path = segments.join('/');
  const url = new URL(`/api/v1/${path}`, BACKEND_URL);
  url.search = request.nextUrl.search;

  const cookieStore = await cookies();
  const token = cookieStore.get(AUTH_COOKIE_NAME)?.value;

  const headers = new Headers();
  headers.set('Accept', 'application/json');
  const contentType = request.headers.get('content-type');
  if (contentType) headers.set('Content-Type', contentType);
  if (token) headers.set('Authorization', `Bearer ${token}`);

  const incomingRequestId = request.headers.get('x-request-id');
  if (incomingRequestId) headers.set('X-Request-Id', incomingRequestId);

  const hasBody = !['GET', 'HEAD'].includes(request.method);
  const body = hasBody ? await request.text() : undefined;

  let upstream: Response;
  try {
    upstream = await fetch(url, {
      method: request.method,
      headers,
      body: body && body.length > 0 ? body : undefined,
      cache: 'no-store',
    });
  } catch {
    return NextResponse.json(
      { error: { code: 'SERVICE_UNAVAILABLE', message: 'Backend tidak dapat dihubungi.', details: [] }, request_id: incomingRequestId ?? '' },
      { status: 503 },
    );
  }

  const responseBody = await upstream.text();

  if (upstream.status === 401) {
    const res = new NextResponse(responseBody, {
      status: upstream.status,
      headers: { 'Content-Type': upstream.headers.get('content-type') ?? 'application/json' },
    });
    res.cookies.delete(AUTH_COOKIE_NAME);
    return res;
  }

  const res = new NextResponse(responseBody, {
    status: upstream.status,
    headers: { 'Content-Type': upstream.headers.get('content-type') ?? 'application/json' },
  });
  const reqId = upstream.headers.get('x-request-id');
  if (reqId) res.headers.set('X-Request-Id', reqId);
  return res;
}

type RouteContext = { params: Promise<{ path: string[] }> };

export async function GET(request: NextRequest, { params }: RouteContext) {
  const { path } = await params;
  return proxy(request, path);
}

export async function POST(request: NextRequest, { params }: RouteContext) {
  const { path } = await params;
  return proxy(request, path);
}

export async function PATCH(request: NextRequest, { params }: RouteContext) {
  const { path } = await params;
  return proxy(request, path);
}

export async function PUT(request: NextRequest, { params }: RouteContext) {
  const { path } = await params;
  return proxy(request, path);
}

export async function DELETE(request: NextRequest, { params }: RouteContext) {
  const { path } = await params;
  return proxy(request, path);
}
