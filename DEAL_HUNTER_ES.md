# PC Deal Hunter — guía rápida

Esta versión local combina tres fuentes distintas:

- **Catálogo de componentes:** BuildCores OpenDB aporta nombres, modelos,
  especificaciones e identificadores de CPU, GPU, RAM, SSD y PSU.
- **Ofertas curadas:** el RSS oficial de DealNews aporta precios y fecha de
  publicación de ofertas de componentes verificadas editorialmente.
- **Descubrimientos:** un buscador privado local consulta el índice web para Amazon,
  Walmart, Micro Center, Newegg, Best Buy y GameStop. Si se configura una clave
  oficial de Best Buy, sus precios pasan a venir de su API casi en tiempo real.

## Cómo usarla

1. Abra **PC Deal Hunter** desde el acceso directo del escritorio.
2. Entre en **PC deals → Deal hunter**. Al abrirlo se actualizan las búsquedas
   que tengan seis horas o más sin revisarse.
   La pestaña **Cheapest (7 days)** muestra primero ofertas recientes con precio;
   **Best today** limita la lista a las publicadas o encontradas hoy.
3. Use **Hunt a component** y escriba un modelo concreto, por ejemplo
   `AMD Ryzen 5 7600` o `WD Black SN850X 2TB`.
4. Defina el precio de alerta. La campana de la aplicación avisará cuando
   aparezca un resultado igual o inferior al objetivo.
5. Antes de comprar, abra la tienda y confirme precio, vendedor, condición,
   inventario, envío y garantía. Un precio de “Web index” puede estar atrasado.

## Privacidad y límites

La aplicación corre solo en `localhost`; la base y contraseñas permanecen en su
PC. Consume el RSS público de DealNews, no automatiza compras ni elude CAPTCHA.
Newegg y GameStop prohíben el scraping
automatizado, por lo que esta versión descubre resultados mediante un índice web
y abre la página para verificación manual. Walmart solo ofrece su búsqueda oficial
de catálogo a vendedores aprobados; Amazon requiere acceso a Creators API.

Fuentes: [BuildCores OpenDB](https://github.com/buildcores/buildcores-open-db),
[RSS oficial de DealNews](https://www.dealnews.com/rss),
[Best Buy APIs](https://developer.bestbuy.com/apis),
[Amazon Creators API](https://affiliate-program.amazon.com/creatorsapi/docs/),
[Walmart Marketplace API](https://developer.walmart.com/us-marketplace/docs/item-search-for-the-walmart-catalog),
[restricción de automatización de Newegg](https://kb.newegg.com/knowledge-base/auto-refresher-ip-ban),
[condiciones de GameStop](https://www.gamestop.com/disclaimer.html).
