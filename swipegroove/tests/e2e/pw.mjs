// Gemeinsame Playwright-Hilfen für lokale Browsertests
import { chromium } from 'playwright-core';
export const BASE = process.env.SG_BASE || 'http://127.0.0.1:8080/groove';
export const SHOTS = process.env.SG_SHOTS || '/tmp/claude-0/-home-user-WebReymond/6d59f421-bd08-5f82-bd80-6b99986c1988/scratchpad/shots';
export async function browser() {
  return chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome', args: ['--no-sandbox'] });
}
export function check(cond, msg) { if (!cond) { throw new Error('CHECK FAILED: ' + msg); } console.log('  ok  ' + msg); }
