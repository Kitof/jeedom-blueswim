if [ `dpkg -l | grep php-xml | wc -l` -ne 2 ]; then
  exit 1
fi

