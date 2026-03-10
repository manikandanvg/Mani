import { io } from 'socket.io-client';
import { env } from '@/services/config/env';

export const socket = io(env.socketBaseUrl, {
  autoConnect: false,
  transports: ['websocket']
});
