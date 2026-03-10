import { Pressable, SafeAreaView, StyleSheet, Text, View } from 'react-native';
import { useRouter } from 'expo-router';
import { spacing, colors } from '@/theme/tokens';

export default function HomeScreen() {
  const router = useRouter();

  return (
    <SafeAreaView style={styles.safeArea}>
      <View style={styles.container}>
        <Text style={styles.title}>Connect Starter</Text>
        <Text style={styles.subtitle}>Expo boilerplate for social + chat product modules.</Text>

        <Pressable style={styles.button} onPress={() => router.push('/modules')}>
          <Text style={styles.buttonText}>Open Module Map</Text>
        </Pressable>
      </View>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safeArea: { flex: 1, backgroundColor: colors.background },
  container: {
    flex: 1,
    padding: spacing.lg,
    justifyContent: 'center',
    gap: spacing.md
  },
  title: {
    color: colors.text,
    fontSize: 30,
    fontWeight: '700'
  },
  subtitle: {
    color: colors.mutedText,
    fontSize: 16,
    lineHeight: 22
  },
  button: {
    backgroundColor: colors.primary,
    borderRadius: 10,
    paddingVertical: spacing.md,
    paddingHorizontal: spacing.lg,
    marginTop: spacing.sm
  },
  buttonText: {
    color: '#fff',
    fontSize: 16,
    fontWeight: '600',
    textAlign: 'center'
  }
});
