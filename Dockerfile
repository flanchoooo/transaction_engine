FROM centos:7

USER root

RUN yum update -y \
    && yum -y install epel-release yum-utils \
    && yum -y install http://rpms.remirepo.net/enterprise/remi-release-7.rpm \
    && yum-config-manager --enable remi-php73 \
    && yum -y install php php-common php-opcache php-mcrypt php-cli \
        php-gd php-curl php-mysqlnd php-mbstring php-zip php-mcrypt \
        pdo pdo_msql php7.3-gd php-dom \
        httpd unzip \
    && yum clean all


#RUN systemctl enable httpd.service

#Install composer
WORKDIR /tmp
RUN curl -sS https://getcomposer.org/installer | php \
    && mv composer.phar /usr/local/bin/composer


#Install app
WORKDIR /var/www/html

#Copy files
ADD . /var/www/html
RUN mv .env.prod .env

ADD  httpd.conf /etc/httpd/conf/httpd.conf
ADD  welcome.conf /etc/httpd/conf.d/welcome.conf

RUN chown -R apache:apache /var/www/html/storage/ /var/www/html/bootstrap/cache/ \
    && composer install --no-dev \
    && php artisan cache:clear


EXPOSE 8080

ENTRYPOINT ["/usr/sbin/httpd","-D","FOREGROUND"]
