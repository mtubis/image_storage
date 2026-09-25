import { screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { App } from '@/App';
import { renderWithProviders } from '@/test/render';

describe('App', () => {
  it('renders the application heading', () => {
    renderWithProviders(<App />);

    expect(screen.getByRole('heading', { level: 1, name: 'Image Storage' })).toBeInTheDocument();
  });
});
