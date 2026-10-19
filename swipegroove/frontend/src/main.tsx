import { render } from 'preact';
import '@fontsource/sora/latin-400.css';
import '@fontsource/sora/latin-600.css';
import '@fontsource/sora/latin-700.css';
import '@fontsource/sora/latin-800.css';
import '@fontsource/jetbrains-mono/latin-400.css';
import './styles.css';
import { App } from './app';

render(<App />, document.getElementById('app')!);
