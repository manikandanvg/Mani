import { apiRequest } from '@/services/api/client';

type OtpPayload = { phone: string };
type VerifyPayload = { phone: string; code: string };

export function sendOtp(payload: OtpPayload) {
  return apiRequest<{ success: boolean }>('/auth/send-otp', { method: 'POST', body: payload });
}

export function verifyOtp(payload: VerifyPayload) {
  return apiRequest<{ token: string }>('/auth/verify-otp', { method: 'POST', body: payload });
}
