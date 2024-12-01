# Use PrestaShop 8.2 as base image
FROM prestashop/prestashop:8.2

WORKDIR /var/www/html

RUN apt-get update && apt-get install -y \
  git \
  
# Set permissions if necessary
RUN chown -R www-data:www-data /var/www/html
  
  
# Expose port 80
EXPOSE 80

CMD ["apache2-foreground"]