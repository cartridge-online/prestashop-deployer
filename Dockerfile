# Use PrestaShop 8.2 as the base image
FROM prestashop/prestashop:8.2.0

# Set working directory to /var/www/html
WORKDIR /var/www/html

# Ensure necessary packages are installed (optional)
RUN apt install git

# Copy the 'deployer' folder from /var/www to /var/www/html
COPY ./deployer /var/www/html/deployer/

# Set permissions for PrestaShop files and deployer folder
RUN chown -R www-data:www-data /var/www/html

# Expose port 80 for the web server
EXPOSE 80

# Set the default command to start Apache
CMD ["apache2-foreground"]
