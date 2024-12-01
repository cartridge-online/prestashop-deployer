# Use PrestaShop 8.2 as the base image
FROM prestashop/prestashop:8.2

# Set working directory inside the container
WORKDIR /var/www/html

# Install necessary packages (git, etc.)
RUN apt-get update && apt-get install -y \
  git \
  && rm -rf /var/lib/apt/lists/*  # Clean up after installation

# Copy PrestaShop files into the container
COPY . /var/www/html/

# Set permissions for PrestaShop files
RUN chown -R www-data:www-data /var/www/html

# Expose port 80 for the web server
EXPOSE 80

# Set the default command to start Apache
CMD ["apache2-foreground"]
