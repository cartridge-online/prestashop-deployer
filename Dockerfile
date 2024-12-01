# Use PrestaShop 8.2 as base image
FROM prestashop/prestashop:8.2

WORKDIR /var/www/html

# Update package lists and install git
RUN apt-get update && apt-get install -y git && apt-get clean

# Set permissions if necessary
RUN chown -R www-data:www-data /var/www/html

# Expose port 80
EXPOSE 80

CMD ["apache2-foreground"]
