// @ts-check
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const BASE = process.env.PORTAL_URL || 'https://www.sv-netzwerk.eu';
const INTERN_URL = `${BASE}/intern/fensterpruefung-bonn/`;
const API_URL = `${BASE}/intern/api`;

const ROLES = [
  { name: 'admin', email: 'info@sv-netzwerk.eu', password: '@Stauffenberg10@Stauffenberg20' },
  // Test users (will only work if test data is seeded):
  // { name: 'pruefer', email: 'pruefer1@testprojekt.local', password: 'Test2026!' },
  // { name: 'gast', email: 'gast@testprojekt.local', password: 'Test2026!' },
];

const PAGES = [
  { route: 'dashboard', label: 'Dashboard' },
  { route: 'gebaeude', label: 'Gebäude' },
  { route: 'etagen', label: 'Etagen' },
  { route: 'raeume', label: 'Räume' },
  { route: 'fenster', label: 'Fenster' },
  { route: 'fluegel', label: 'Flügel' },
  { route: 'fotos', label: 'Fotos' },
  { route: 'export', label: 'Export' },
  { route: 'users', label: 'Benutzer' },
  { route: 'ai-import', label: 'KI-Import' },
];

const screenshotDir = path.join(__dirname, '..', 'analysis_export', 'screenshots');

test.describe('Portal Screenshot Documentation', () => {
  
  for (const role of ROLES) {
    test.describe(`Rolle: ${role.name}`, () => {
      
      test.beforeAll(async () => {
        const dir = path.join(screenshotDir, role.name);
        if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
      });

      test(`Login als ${role.name}`, async ({ page }) => {
        await page.goto(INTERN_URL);
        await page.waitForTimeout(2000);
        
        // Take login page screenshot
        await page.screenshot({ 
          path: path.join(screenshotDir, role.name, '00_login.png'),
          fullPage: true 
        });
        
        // Fill login form
        const emailInput = page.locator('input[type="email"], input[name="email"]');
        const passInput = page.locator('input[type="password"], input[name="password"]');
        
        if (await emailInput.count() > 0) {
          await emailInput.fill(role.email);
          await passInput.fill(role.password);
          
          // Submit
          const btn = page.locator('button[type="submit"], .intern-login-btn, button:has-text("Anmelden")');
          if (await btn.count() > 0) {
            await btn.first().click();
            await page.waitForTimeout(3000);
          }
        }
        
        // Screenshot after login
        await page.screenshot({
          path: path.join(screenshotDir, role.name, '01_nach_login.png'),
          fullPage: true
        });
      });

      for (const pg of PAGES) {
        test(`Seite: ${pg.label} (${role.name})`, async ({ page }) => {
          // Login first
          await page.goto(INTERN_URL);
          await page.waitForTimeout(1000);
          
          const emailInput = page.locator('input[type="email"], input[name="email"]');
          if (await emailInput.count() > 0) {
            await emailInput.fill(role.email);
            await page.locator('input[type="password"]').fill(role.password);
            const btn = page.locator('button[type="submit"], button:has-text("Anmelden")');
            if (await btn.count() > 0) await btn.first().click();
            await page.waitForTimeout(2000);
          }
          
          // Navigate to page via hash
          await page.goto(`${INTERN_URL}#/${pg.route}`);
          await page.waitForTimeout(2000);
          
          // Full page screenshot
          await page.screenshot({
            path: path.join(screenshotDir, role.name, `${pg.route}.png`),
            fullPage: true
          });
          
          // PDF output
          await page.pdf({
            path: path.join(screenshotDir, role.name, `${pg.route}.pdf`),
            format: 'A4',
            printBackground: true
          }).catch(() => {}); // PDF may fail in non-headless
          
          // HTML snapshot
          const html = await page.content();
          fs.writeFileSync(
            path.join(screenshotDir, role.name, `${pg.route}.html`),
            html, 'utf8'
          );
        });
      }

      test(`Dialoge dokumentieren (${role.name})`, async ({ page }) => {
        await page.goto(INTERN_URL);
        await page.waitForTimeout(1000);
        
        const emailInput = page.locator('input[type="email"]');
        if (await emailInput.count() > 0) {
          await emailInput.fill(role.email);
          await page.locator('input[type="password"]').fill(role.password);
          const btn = page.locator('button[type="submit"], button:has-text("Anmelden")');
          if (await btn.count() > 0) await btn.first().click();
          await page.waitForTimeout(2000);
        }

        // Try to open "Gebäude hinzufügen" dialog
        await page.goto(`${INTERN_URL}#/gebaeude`);
        await page.waitForTimeout(1500);
        
        const addBtn = page.locator('button:has-text("Hinzufügen"), button:has-text("Neu"), .intern-btn--primary');
        if (await addBtn.count() > 0) {
          await addBtn.first().click();
          await page.waitForTimeout(1000);
          await page.screenshot({
            path: path.join(screenshotDir, role.name, 'dialog_neu_gebaeude.png'),
            fullPage: true
          });
        }

        // Try action menus
        const actionBtn = page.locator('.intern-action-btn, button:has-text("⋮"), .intern-dropdown-toggle');
        if (await actionBtn.count() > 0) {
          await actionBtn.first().click();
          await page.waitForTimeout(500);
          await page.screenshot({
            path: path.join(screenshotDir, role.name, 'dialog_aktionsmenue.png'),
            fullPage: true
          });
        }
      });
    });
  }
});

test('Berechtigungsprotokoll', async ({ page }) => {
  const results = [];
  
  for (const role of ROLES) {
    // Login
    await page.goto(INTERN_URL);
    await page.waitForTimeout(1000);
    const emailInput = page.locator('input[type="email"]');
    if (await emailInput.count() > 0) {
      await emailInput.fill(role.email);
      await page.locator('input[type="password"]').fill(role.password);
      const btn = page.locator('button[type="submit"], button:has-text("Anmelden")');
      if (await btn.count() > 0) await btn.first().click();
      await page.waitForTimeout(2000);
    }
    
    // Check each page
    for (const pg of PAGES) {
      await page.goto(`${INTERN_URL}#/${pg.route}`);
      await page.waitForTimeout(1000);
      
      const content = await page.textContent('body');
      const hasAccess = !content.includes('Keine Berechtigung') && 
                        !content.includes('Zugriff verweigert');
      results.push({ role: role.name, page: pg.route, access: hasAccess });
    }
    
    // Logout
    const logoutBtn = page.locator('button:has-text("Abmelden"), a:has-text("Logout")');
    if (await logoutBtn.count() > 0) await logoutBtn.first().click();
    await page.waitForTimeout(1000);
  }
  
  // Write permission report
  let report = "BERECHTIGUNGSPROTOKOLL\n======================\n\n";
  report += "Rolle          | Seite       | Zugriff\n";
  report += "-------------- | ----------- | -------\n";
  for (const r of results) {
    report += `${r.role.padEnd(14)} | ${r.page.padEnd(11)} | ${r.access ? '✓' : '✗'}\n`;
  }
  
  fs.writeFileSync(
    path.join(screenshotDir, 'berechtigungsprotokoll.txt'),
    report, 'utf8'
  );
});
