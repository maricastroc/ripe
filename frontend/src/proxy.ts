import { NextResponse, type NextRequest } from "next/server";
import { apiOrigin, forwardedHeaders } from "@/lib/gateway";

export function proxy(request: NextRequest) {
  const target = new URL(`${request.nextUrl.pathname}${request.nextUrl.search}`, apiOrigin());
  return NextResponse.rewrite(target, { request: { headers: forwardedHeaders(request.headers) } });
}

export const config = {
  matcher: "/api/:path*",
};
