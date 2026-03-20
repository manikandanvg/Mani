import * as SecureStore from 'expo-secure-store';

const TOKEN_KEY = 'access_token';

export function setAccessToken(token: string) {
  return SecureStore.setItemAsync(TOKEN_KEY, token);
}

export function getAccessToken() {
  return SecureStore.getItemAsync(TOKEN_KEY);
}

export function clearAccessToken() {
  return SecureStore.deleteItemAsync(TOKEN_KEY);
}
