import { ScrollView, StyleSheet, Text, View } from 'react-native';
import { moduleMap } from '@/shared/constants/moduleMap';
import { colors, spacing } from '@/theme/tokens';

export default function ModulesScreen() {
  return (
    <ScrollView style={styles.screen} contentContainerStyle={styles.content}>
      {moduleMap.map((module) => (
        <View key={module.name} style={styles.card}>
          <Text style={styles.name}>{module.name}</Text>
          <Text style={styles.description}>{module.description}</Text>
        </View>
      ))}
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  screen: {
    flex: 1,
    backgroundColor: colors.background
  },
  content: {
    padding: spacing.lg,
    gap: spacing.md
  },
  card: {
    borderRadius: 12,
    padding: spacing.md,
    backgroundColor: '#172036',
    borderWidth: 1,
    borderColor: '#283554'
  },
  name: {
    color: colors.text,
    fontSize: 18,
    fontWeight: '700',
    marginBottom: spacing.xs
  },
  description: {
    color: colors.mutedText,
    fontSize: 14,
    lineHeight: 20
  }
});
