# rbkmoney-cms-joomla-virtuemart

### Инструкция по установке

Перед установкой, создаем архив `rbkmoneypayment.zip` помещая в него содержимое папки `rbkmoneypayment`.

1. Устанавливаем плагин через менеджер расширений (`administrator/index.php?option=com_installer&view=install`)
2. Включаем плагин (`administrator/index.php?option=com_installer&view=manage`)
3. Добавляем способ оплаты в Virtuemart (`administrator/index.php?option=com_virtuemart&view=paymentmethod`)
    1. Название - RBKmoney, опубликовано - да, платежный метод - RBKmoney.
	2. Во вкладке **Конфигурация** прописываем данные полученные в системе RBKmoney.
4. В личном кабинете RBKmoney прописываем адрес оповещения о платеже:
```
http://YOUR_SITE_NAME/index.php?option=com_virtuemart&view=pluginresponse&task=pluginnotification&pm=rbkmoney
```
