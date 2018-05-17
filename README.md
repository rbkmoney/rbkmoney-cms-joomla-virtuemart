# rbkmoney-cms-joomla-virtuemart


Пожалуйста, обязательно делайте бекапы!

Платежный плагин RBKmoney для Joomla + VirtueMart (без поддержки 54ФЗ)

Модуль разрабатывался и тестировался на версиях:
- Joomla 3.6.5
- VirtueMart 3.2.1


#### Требования

- PHP 5.4 (минимум)
- OpenSSL - 1.0.2k-fips (минимум)
- Curl


### Установка и настройка модуля

Перед установкой, создаем архив `rbkmoneycheckout.zip` помещая в него содержимое папки `rbkmoneycheckout`.


1. Устанавливаем плагин через менеджер расширений (`administrator/index.php?option=com_installer&view=install`)

![Install](images/install.png)

2. Выбираем наш архив и устанавливаем

![Upload](images/upload.png)


3. Включаем плагин (`administrator/index.php?option=com_installer&view=manage`)

![Activated](images/activated.png)


4. Выбираем платежные методы
![Payment methods](images/payment_methods.png)


5. Добавляем способ оплаты в Virtuemart (`administrator/index.php?option=com_virtuemart&view=paymentmethod`)

![List payment methods](images/list_payment_methods.png)

Выбираем модуль RBKmoney, в нем на первой вкладке:

![Сommon settings](images/common_settings.png)

- Название - RBKmoney
- опубликовано - да
- платежный метод - RBKmoney.


После чего можем заняться настройкой модуля.



#### Для начала приема платежей на Вашем сайте осталось совсем немного

Во вкладке **Конфигурация** прописываем данные полученные в системе RBKmoney.



![Custom settings](images/custom_settings.png)

Настройте плагин в соответствии с данными из [личного кабинета RBKmoney](https://dashboard.rbk.money).

`Shop ID` - идентификатор магазина из RBKmoney. Скопируйте его в Личном кабинете RBKmoney в разделе Детали магазина, поле Идентификатор;

`Приватный ключ` - ключ для доступа к API. Скопируйте его в Личном кабинете RBKmoney в разделе API Ключ

`Публичный ключ` - ключ для обработки уведомлений о смене статуса

- Заходим в личный кабинет RBKmoney: Создать Webhook;
- Вставляем в поле URL вида `http://YOUR_SITE_NAME/index.php?option=com_virtuemart&view=pluginresponse&task=pluginnotification&pm=rbkmoneycheckout`, скопированного из `URL для уведомлений`
- Выбираем Типы событий `InvoicePaid` и `Invoice Canсelled`;
- после создания Webhook-а копируем Публичный ключ после нажатия Показать детали;
- скопированный ключ вставляем в поле `Публичный ключ` на странице настроек модуля;


- Сохраните изменения и проведите тестовый платеж

Логи доступны по пути `VirtueMart / Tools / Logs`, после чего выбираем необходимый файл с логами

![Настройки](images/virtuemart.png)

После чего можем выбрать логи и ознакомиться с содержимом интересущего нас файла

![Логи доступны](images/logs.png)



### Нашли ошибку или у вас есть предложение по улучшению модуля?

Пишите нам support@rbkmoney.com При обращении необходимо:

- Указать наименование CMS и компонента магазина, а также их версии
- Указать версию платежного модуля (доступна на странице Управление пакетами)
- Описать проблему или предложение
- Приложить снимок экрана (для большей информативности)
