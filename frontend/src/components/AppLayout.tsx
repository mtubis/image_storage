import type { ReactNode } from 'react';
import styles from './AppLayout.module.css';

interface AppLayoutProps {
  children?: ReactNode;
}

export function AppLayout({ children }: AppLayoutProps) {
  return (
    <>
      <header className={styles.header}>
        <div className={styles.inner}>
          <h1 className={styles.title}>Image Storage</h1>
        </div>
      </header>
      <main className={styles.main}>
        <div className={styles.inner}>{children}</div>
      </main>
    </>
  );
}
