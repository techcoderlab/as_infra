/** @type {import('tailwindcss').Config} */
export default {
  darkMode: 'class',
  content: ['./index.html', './src/**/*.{vue,js,ts,jsx,tsx}'],
  theme: {
    extend: {
      fontFamily: {
        sans: ['Inter', 'sans-serif'], // Enforce consistent typography
      }
      // We rely on standard Tailwind colors, but you can define semantic aliases here if preferred.
      // For this implementation, we will use utility classes directly for clarity.
    },
  },
  plugins: [],
}


