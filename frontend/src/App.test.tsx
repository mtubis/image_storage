import { screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { App } from '@/App';
import { renderWithProviders } from '@/test/render';

describe('App', () => {
  it('renders the application heading and the image list', async () => {
    renderWithProviders(<App />);

    expect(screen.getByRole('heading', { level: 1, name: 'Image Storage' })).toBeInTheDocument();
    // Waiting for the list's request keeps it from outliving the test.
    expect(await screen.findByText('No images uploaded yet.')).toBeInTheDocument();
  });
});
