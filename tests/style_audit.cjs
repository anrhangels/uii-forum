const {chromium}=require('../runtime/browser/node_modules/playwright');
(async()=>{
  const browser=await chromium.launch({headless:true});
  try {
    const page=await browser.newPage();const errors=[];
    page.on('pageerror',error=>errors.push(error.message));
    await page.goto('http://127.0.0.1:8088/ucp.php?mode=login');
    await page.locator('#username').fill(process.env.UII_TEST_USER||'Test_Admin');
    await page.locator('#password').fill(process.env.UII_TEST_PASSWORD);
    await page.waitForTimeout(2100);await page.locator('input[name=login]').click();
    const paths=['/','/app.php/uii/tasks','/app.php/uii/cases','/app.php/uii/new?kind=inspection','/app.php/uii/employees','/app.php/uii/account','/app.php/uii/settings','/app.php/uii/activity'];
    for(const theme of ['light','dark']) {
      await page.evaluate(theme=>localStorage.setItem('uii-theme',theme),theme);
      for(const width of [360,768,1440]) {
        await page.setViewportSize({width,height:960});
        for(const path of paths) {
          await page.goto('http://127.0.0.1:8088'+path);
          if(await page.locator('html').getAttribute('data-theme')!==theme)throw Error('Theme '+path);
          const dimensions=await page.evaluate(()=>[document.documentElement.scrollWidth,innerWidth]);
          if(dimensions[0]>dimensions[1])throw Error(`Overflow ${width} ${path}: ${dimensions}`);
          if(await page.locator('.portal-contacts,.page-footer .copyright,.page-footer>.navbar,a[href*="mode=privacy"],a[href*="mode=terms"]').count())throw Error('Redundant footer/links '+path);
          if(await page.locator('.work-calendar').count())throw Error('Removed calendar '+path);
          if(await page.getByText('Последние действия',{exact:true}).count())throw Error('Removed activity '+path);
          if(await page.locator('.portal-symbols').count()!==1)throw Error('Missing icon sprite '+path);
          const broken=await page.locator('img').evaluateAll(images=>images.filter(image=>!image.complete||image.naturalWidth===0).map(image=>image.src));
          if(broken.length)throw Error('Broken images '+broken.join(', '));
        }
      }
    }
    for(const mode of ['privacy','terms']){
      const response=await page.goto('http://127.0.0.1:8088/ucp.php?mode='+mode);
      if(![403,404].includes(response.status()))throw Error('Disabled page '+mode);
    }
    if(errors.length)throw Error(errors.join('\n'));
    console.log('PASS both themes, 360/768/1440px, footer removal, icons, images and disabled legal pages');
  } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exit(1);});
