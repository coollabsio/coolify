APP=epicyon
VERSION=1.1.0

all:
debug:
source:
	rm -f *.*~ *~
	rm -f ontology/*~
	rm -f ontology/*.new
	rm -f translations/*~
	rm -f orgs/*~
	rm -f scripts/*~
	rm -f deploy/*~
	rm -rf __pycache__
	rm -f \#* \.#* src/*~
	rm -fr deb.*
	rm -f ../${APP}*.deb ../${APP}*.changes ../${APP}*.asc ../${APP}*.dsc
	cd .. && mv ${APP} ${APP}-${VERSION} && tar -zcvf ${APP}_${VERSION}.orig.tar.gz ${APP}-${VERSION}/ && mv ${APP}-${VERSION} ${APP}
clean:
	rm -f \#*
	rm -f *.*~ *~ *.dot
	rm -f orgs/*~
	rm -f ontology/*~
	rm -f ontology/*.new
	rm -f defaultwelcome/*~
	rm -f theme/indymediaclassic/welcome/*~
	rm -f theme/indymediamodern/welcome/*~
	rm -f website/EN/*~
	rm -f cwlists/*~
	rm -f gemini/EN/*~
	rm -f scripts/*~
	rm -f deploy/*~
	rm -f translations/*~
	rm -f flycheck_*
	rm -rf __pycache__
	rm -f calendar.css blog.css epicyon.css follow.css login.css options.css search.css suspended.css
