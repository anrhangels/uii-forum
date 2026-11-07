const {chromium}=require('../runtime/browser/node_modules/playwright');
(async()=>{
 const browser=await chromium.launch();const page=await browser.newPage({viewport:{width:1440,height:1100}});
 const errors=[];const external=[];page.on('pageerror',e=>errors.push(e.message));page.on('request',r=>{if(!r.url().startsWith('http://127.0.0.1:8088/')&&!r.url().startsWith('data:'))external.push(r.url());});
 await page.goto('http://127.0.0.1:8088/ucp.php?mode=login');await page.locator('#username').fill(process.env.UII_TEST_USER||'Test_Admin');await page.locator('#password').fill(process.env.UII_TEST_PASSWORD);await page.waitForTimeout(2100);await page.locator('input[name=login]').click();
 await page.goto('http://127.0.0.1:8088/');
 if(await page.locator('.work-calendar,.calendar-day').count())throw Error('Removed calendar is present');
 if(await page.getByText('Последние действия',{exact:true}).count())throw Error('Removed recent activity is present');
 if(await page.locator('.portal-contacts,.page-footer .copyright,.page-footer>.navbar').count())throw Error('Removed footer is present');
 if(await page.locator('a[href*="mode=privacy"],a[href*="mode=terms"]').count())throw Error('Removed legal links are present');
 await page.screenshot({path:'runtime/workspace-calendar.png',fullPage:true});
 console.log('PASS compact home, removed calendar/activity/footer');
 await page.setViewportSize({width:390,height:844});await page.reload();
 let dims=await page.evaluate(()=>[document.documentElement.scrollWidth,innerWidth]);if(dims[0]>dims[1])throw Error('Home page overflow '+dims);
 await page.screenshot({path:'runtime/workspace-calendar-mobile.png',fullPage:true});
 console.log('PASS mobile home layout');
 await page.setViewportSize({width:1440,height:1100});await page.goto('http://127.0.0.1:8088/app.php/uii/admin');
 const href=await page.getByRole('link',{name:'Открыть системную панель',exact:false,includeHidden:true}).getAttribute('href');
 const acpUrl=new URL(href,page.url()).href;await page.goto(acpUrl);
 if(await page.locator('input[type=password]').count()){
  await page.locator('input[type=password]').fill(process.env.UII_TEST_PASSWORD);await page.waitForTimeout(2100);await page.locator('input[name=login]').click();await page.locator('.uii-acp-bar').waitFor({timeout:15000});
 }
 await page.screenshot({path:'runtime/acp-before-check.png',fullPage:true});
 if(await page.locator('.uii-acp-bar').count()!==1)throw Error('ACP extension theme not loaded at '+page.url());
 const tabsText=await page.locator('#tabs').innerText();if(!tabsText.toLocaleLowerCase('ru').includes('основное'))throw Error('ACP navigation localization: '+tabsText);
 const mainBox=await page.locator('#main').boundingBox();if(!mainBox||mainBox.y>600)throw Error('ACP main content pushed below menu');
 const bg=await page.evaluate(()=>getComputedStyle(document.documentElement).getPropertyValue('--a-bg').trim());if(!bg)throw Error('ACP CSS not loaded');
 if(await page.locator('.portal-contacts').count())throw Error('Removed ACP footer is present');
 await page.screenshot({path:'runtime/acp-modern.png',fullPage:true});
 await page.locator('#acp-theme').click();if(await page.locator('html').getAttribute('data-theme')!=='dark')throw Error('ACP dark theme');
 await page.screenshot({path:'runtime/acp-modern-dark.png',fullPage:true});
 await page.setViewportSize({width:390,height:844});
 dims=await page.evaluate(()=>[document.documentElement.scrollWidth,innerWidth]);if(dims[0]>dims[1])throw Error('ACP mobile overflow '+dims);
 await page.screenshot({path:'runtime/acp-modern-mobile.png',fullPage:true});
 if(errors.length)throw Error(errors.join('\n'));if(external.length)throw Error('External assets: '+external.join(', '));
 await page.setViewportSize({width:1440,height:1100});
 const settingsLink=page.locator('#menu a[href*="mode=settings"]').first();
 if(await settingsLink.count()){await settingsLink.click();if(!await page.locator('input[name="config[sitename]"]').isVisible())throw Error('ACP board settings form');await page.screenshot({path:'runtime/acp-settings-modern.png',fullPage:true});}
 await page.goto('http://127.0.0.1:8088/posting.php?mode=smilies');
 if(await page.locator('.portal-contacts,.copyright').count())throw Error('Removed technical footer is present');
 console.log('PASS actual ACP design, settings form, dark mode, footer, mobile and local assets');
 console.log('PASS no redundant blocks on simple technical pages');
 await browser.close();
})().catch(e=>{console.error(e);process.exit(1)});
