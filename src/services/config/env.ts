import Constants from 'expo-constants';

type Extra = {
  apiBaseUrl?: string;
  socketBaseUrl?: string;
};

const extra = (Constants.expoConfig?.extra ?? {}) as Extra;

export const env = {
  apiBaseUrl: extra.apiBaseUrl ?? 'https://api.example.com',
  socketBaseUrl: extra.socketBaseUrl ?? 'https://api.example.com'
};
