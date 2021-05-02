PROG=0
echo $PROG > $1
cd `dirname $0`
chmod a+x *.sh
NBSTEPS=`ls */install.sh | wc -l`
STEP=$((100/$NBSTEPS))
for i in */install.sh; do
  echo -- installing $i --
  PROG=$(($PROG+$STEP))
  chmod a+x $i
  eval $i
  echo $PROG > $1
done
rm $1
